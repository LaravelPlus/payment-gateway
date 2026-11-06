<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Listeners;

use Illuminate\Support\Facades\Log;
use LaravelPlus\PaymentGateway\Enums\PaymentStatus;
use LaravelPlus\PaymentGateway\Enums\SubscriptionStatus;
use LaravelPlus\PaymentGateway\Events\PaymentFailed;
use LaravelPlus\PaymentGateway\Events\PaymentSucceeded;
use LaravelPlus\PaymentGateway\Events\PaymentWebhookReceived;
use LaravelPlus\PaymentGateway\Events\RefundProcessed;
use LaravelPlus\PaymentGateway\Events\SubscriptionCanceled;
use LaravelPlus\PaymentGateway\Events\SubscriptionCreated;
use LaravelPlus\PaymentGateway\Models\Invoice;
use LaravelPlus\PaymentGateway\Models\PaymentCustomer;
use LaravelPlus\PaymentGateway\Models\Plan;
use LaravelPlus\PaymentGateway\Models\Refund;
use LaravelPlus\PaymentGateway\Models\Subscription;
use LaravelPlus\PaymentGateway\Models\Transaction;
use LaravelPlus\PaymentGateway\Services\InvoicePdfGenerator;
use UnexpectedValueException;

/**
 * Handle PayPal webhook events and update database accordingly.
 *
 * Rows are matched on (driver, provider_id). An event that can't be placed
 * yet (unknown subscriber, sale before its subscription) throws, so the
 * webhook answers 5xx and PayPal redelivers it later.
 */
final class HandlePayPalWebhook
{
    public function __construct(
        private readonly InvoicePdfGenerator $pdfGenerator,
    ) {}

    /**
     * Handle the event.
     */
    public function handle(PaymentWebhookReceived $event): void
    {
        if ($event->payload->driver !== 'paypal') {
            return;
        }

        $type = $event->payload->type;
        $data = $event->payload->data;

        Log::info('Processing PayPal webhook', ['type' => $type]);

        match ($type) {
            // Payment events
            'PAYMENT.CAPTURE.COMPLETED' => $this->handlePaymentCompleted($data),
            'PAYMENT.CAPTURE.DENIED' => $this->handlePaymentDenied($data),
            'PAYMENT.CAPTURE.REFUNDED' => $this->handlePaymentRefunded($data),

            // Order events
            'CHECKOUT.ORDER.APPROVED' => Log::info('PayPal order approved', ['order_id' => $data['id'] ?? null]),
            'CHECKOUT.ORDER.COMPLETED' => $this->handleOrderCompleted($data),

            // Subscription events: each carries the full subscription, so all of them sync it.
            'BILLING.SUBSCRIPTION.CREATED',
            'BILLING.SUBSCRIPTION.ACTIVATED',
            'BILLING.SUBSCRIPTION.UPDATED',
            'BILLING.SUBSCRIPTION.CANCELLED',
            'BILLING.SUBSCRIPTION.SUSPENDED',
            'BILLING.SUBSCRIPTION.EXPIRED' => empty($data['id']) ? null : $this->syncSubscription($data),

            // Payment sale events (for subscriptions)
            'PAYMENT.SALE.COMPLETED' => $this->handleSaleCompleted($data),
            'PAYMENT.SALE.REFUNDED' => $this->handleSaleRefunded($data),

            default => Log::debug('Unhandled PayPal webhook type', ['type' => $type]),
        };
    }

    /**
     * charge() stores the order id (PayPal's capture response is the order), so
     * a capture event is matched on its own id and on its order's.
     */
    private function handlePaymentCompleted(array $data): void
    {
        $transaction = $this->findTransaction([$data['id'] ?? null, $data['supplementary_data']['related_ids']['order_id'] ?? null]);

        if ($transaction) {
            $this->markSucceeded($transaction, $data);
        }
    }

    private function handlePaymentDenied(array $data): void
    {
        $transaction = $this->findTransaction([$data['id'] ?? null, $data['supplementary_data']['related_ids']['order_id'] ?? null]);

        if ($transaction) {
            $failureMessage = $data['status_details']['reason'] ?? 'Payment denied';

            $transaction->update([
                'status' => PaymentStatus::Failed->value,
                'failure_message' => $failureMessage,
                'provider_response' => $data,
            ]);

            event(new PaymentFailed($transaction->toDto(), $failureMessage));
        }
    }

    /**
     * The resource is the refund; the refunded capture is its "up" link.
     */
    private function handlePaymentRefunded(array $data): void
    {
        $refundId = $data['id'] ?? null;
        if (!$refundId) {
            return;
        }

        $captureFromLink = null;
        foreach ($data['links'] ?? [] as $link) {
            if (($link['rel'] ?? null) === 'up') {
                $captureFromLink = basename((string) parse_url((string) ($link['href'] ?? ''), PHP_URL_PATH));
            }
        }

        $related = $data['supplementary_data']['related_ids'] ?? [];
        $transaction = $this->findTransaction([$captureFromLink, $related['capture_id'] ?? null, $related['order_id'] ?? null]);

        if (!$transaction) {
            return;
        }

        // ponytail: refund status/amount bookkeeping is gh-25; this only records it against the right payment.
        $refund = Refund::firstOrCreate(
            ['provider_id' => $refundId, 'driver' => 'paypal'],
            [
                'transaction_id' => $transaction->id,
                'user_id' => $transaction->user_id,
                'amount' => $this->parsePayPalAmount($data['amount'] ?? null),
                'currency' => mb_strtoupper($data['amount']['currency_code'] ?? $transaction->currency),
                'status' => $this->mapRefundStatus($data['status'] ?? 'COMPLETED'),
                'reason' => $data['note_to_payer'] ?? null,
                'provider_response' => $data,
            ]
        );

        if ($refund->wasRecentlyCreated) {
            event(new RefundProcessed($refund->toDto()));
        }

        // No forced "refunded": a partial refund leaves the rest paid. The Refund
        // model derives refunded / partially_refunded from the amounts (gh-25).
    }

    private function handleOrderCompleted(array $data): void
    {
        $ids = [$data['id'] ?? null];
        foreach ($data['purchase_units'] ?? [] as $unit) {
            foreach ($unit['payments']['captures'] ?? [] as $capture) {
                $ids[] = $capture['id'] ?? null;
            }
        }

        $transaction = $this->findTransaction($ids);

        if ($transaction) {
            $this->markSucceeded($transaction, $data);
        }
    }

    /**
     * CAPTURE.COMPLETED and ORDER.COMPLETED both report one payment, and either
     * may be redelivered: only the first marks it paid and makes the invoice.
     */
    private function markSucceeded(Transaction $transaction, array $data): void
    {
        if ($transaction->status === PaymentStatus::Succeeded->value) {
            return;
        }

        $transaction->update([
            'status' => PaymentStatus::Succeeded->value,
            'provider_response' => $data,
        ]);

        $invoice = Invoice::createFromTransaction($transaction);
        $this->pdfGenerator->generate($invoice);

        event(new PaymentSucceeded($transaction->toDto()));
    }

    private function handleSaleCompleted(array $data): void
    {
        $saleId = $data['id'] ?? null;
        $agreementId = $data['billing_agreement_id'] ?? null;

        if (!$saleId || !$agreementId) {
            Log::warning('PayPal sale without a subscription ignored', ['sale' => $saleId]);

            return;
        }

        $subscription = Subscription::where('driver', 'paypal')->where('provider_id', $agreementId)->first()
            ?? throw new UnexpectedValueException("PayPal sale {$saleId} belongs to subscription {$agreementId}, which is not synced yet.");

        $transaction = Transaction::firstOrCreate(
            ['provider_id' => $saleId, 'driver' => 'paypal'],
            [
                'user_id' => $subscription->user_id,
                'payable_type' => Subscription::class,
                'payable_id' => $subscription->id,
                'amount' => $this->parsePayPalAmount($data['amount'] ?? null),
                'currency' => mb_strtoupper($data['amount']['currency'] ?? $subscription->currency),
                'status' => PaymentStatus::Succeeded->value,
                'type' => 'subscription',
                'description' => 'Subscription payment',
                'provider_response' => $data,
            ]
        );

        if ($transaction->wasRecentlyCreated) {
            $invoice = Invoice::createFromTransaction($transaction);
            $this->pdfGenerator->generate($invoice);

            event(new PaymentSucceeded($transaction->toDto()));
        }
    }

    private function handleSaleRefunded(array $data): void
    {
        $refundId = $data['id'] ?? null;
        $transaction = $this->findTransaction([$data['sale_id'] ?? null]);

        if (!$refundId || !$transaction) {
            return;
        }

        $refund = Refund::firstOrCreate(
            ['provider_id' => $refundId, 'driver' => 'paypal'],
            [
                'transaction_id' => $transaction->id,
                'user_id' => $transaction->user_id,
                'amount' => $this->parsePayPalAmount($data['amount'] ?? null),
                'currency' => mb_strtoupper($data['amount']['currency'] ?? $transaction->currency),
                'status' => $this->mapRefundStatus($data['state'] ?? 'completed'),
                'provider_response' => $data,
            ]
        );

        if ($refund->wasRecentlyCreated) {
            event(new RefundProcessed($refund->toDto()));
        }
    }

    /**
     * @param  array<int, mixed>  $ids  every PayPal id the payment may have been stored under
     */
    private function findTransaction(array $ids): ?Transaction
    {
        $ids = array_values(array_unique(array_filter($ids, fn ($id) => is_string($id) && $id !== '')));

        $transaction = $ids === [] ? null : Transaction::where('driver', 'paypal')->whereIn('provider_id', $ids)->first();

        if (!$transaction) {
            // Not necessarily ours (e.g. a payment made outside this package), so no retry.
            Log::warning('PayPal event matches no local transaction', ['ids' => $ids]);
        }

        return $transaction;
    }

    /**
     * Upsert the one local row for a PayPal subscription, whatever order its events arrive in.
     */
    private function syncSubscription(array $data): Subscription
    {
        $subscription = Subscription::firstOrNew(['provider_id' => $data['id'], 'driver' => 'paypal']);
        $before = $subscription->status;

        if ($subscription->exists) {
            // Canceled and expired are final; an older snapshot must not roll the status back.
            $storedAt = $subscription->provider_response['status_update_time'] ?? null;
            $incomingAt = $data['status_update_time'] ?? null;

            if (in_array($before, [SubscriptionStatus::Canceled->value, SubscriptionStatus::Expired->value], true)
                || ($storedAt && $incomingAt && strtotime($incomingAt) < strtotime($storedAt))) {
                return $subscription;
            }
        }

        $plan = Plan::where('paypal_plan_id', $data['plan_id'] ?? '')->first();

        // Required on create; never overwritten, because the app may have set them.
        if (!$subscription->exists) {
            $user = $this->findUser($data['subscriber']['payer_id'] ?? null) ?? $this->findUser($data['custom_id'] ?? null)
                ?? throw new UnexpectedValueException("PayPal subscription {$data['id']} matches no local customer by payer_id or custom_id.");

            $subscription->user_id = $user->id;
            $subscription->plan_id = $plan?->slug
                ?? $data['plan_id']
                ?? throw new UnexpectedValueException("PayPal subscription {$data['id']} has no plan_id.");
        }

        $status = $this->mapPayPalStatus($data['status'] ?? 'APPROVAL_PENDING');
        $lastPayment = $data['billing_info']['last_payment']['amount'] ?? null;
        $startTime = $data['start_time'] ?? null;
        $nextBillingTime = $data['billing_info']['next_billing_time'] ?? null;
        $ended = in_array($status, [SubscriptionStatus::Canceled->value, SubscriptionStatus::Expired->value], true);

        $subscription->fill([
            'payment_plan_id' => $plan?->id ?? $subscription->payment_plan_id,
            'provider_plan_id' => $data['plan_id'] ?? $subscription->provider_plan_id,
            'status' => $status,
            'amount' => $lastPayment ? $this->parsePayPalAmount($lastPayment) : ($plan?->amount ?? $subscription->amount ?? 0),
            'currency' => mb_strtoupper($lastPayment['currency_code'] ?? $plan?->currency ?? $subscription->currency ?? 'USD'),
            // ponytail: the subscription resource carries no billing cycle; the local Plan is the source, else monthly.
            'interval' => $plan?->interval ?? $subscription->interval ?? 'month',
            'interval_count' => $plan?->interval_count ?? $subscription->interval_count ?? 1,
            'quantity' => (int) ($data['quantity'] ?? $subscription->quantity ?? 1),
            'current_period_start' => $startTime ? \Carbon\Carbon::parse($startTime) : $subscription->current_period_start,
            'current_period_end' => $nextBillingTime ? \Carbon\Carbon::parse($nextBillingTime) : $subscription->current_period_end,
            'canceled_at' => $status === SubscriptionStatus::Canceled->value ? ($subscription->canceled_at ?? now()) : $subscription->canceled_at,
            'ended_at' => $ended ? ($subscription->ended_at ?? now()) : $subscription->ended_at,
            // PayPal cancels at once; it has no cancel-at-period-end.
            'cancel_at_period_end' => false,
            'provider_response' => $data,
        ])->save();

        if ($subscription->wasRecentlyCreated) {
            event(new SubscriptionCreated($subscription->toDto()));
        }

        if ($status === SubscriptionStatus::Canceled->value && $before !== $status) {
            event(new SubscriptionCanceled($subscription->toDto()));
        }

        return $subscription;
    }

    /**
     * PayPal refund status (v2 COMPLETED/PENDING/FAILED/CANCELLED, v1 sale state) to ours.
     */
    private function mapRefundStatus(string $status): string
    {
        return match (mb_strtoupper($status)) {
            'COMPLETED' => 'completed',
            'PENDING' => 'pending',
            'FAILED' => 'failed',
            'CANCELLED' => 'canceled',
            default => 'pending',
        };
    }

    /**
     * Map PayPal subscription status to our status.
     */
    private function mapPayPalStatus(string $status): string
    {
        return match ($status) {
            'ACTIVE' => SubscriptionStatus::Active->value,
            'APPROVAL_PENDING' => SubscriptionStatus::Incomplete->value,
            'APPROVED' => SubscriptionStatus::Incomplete->value,
            'SUSPENDED' => SubscriptionStatus::Paused->value,
            'CANCELLED' => SubscriptionStatus::Canceled->value,
            'EXPIRED' => SubscriptionStatus::Expired->value,
            default => SubscriptionStatus::Incomplete->value,
        };
    }

    /**
     * Parse PayPal amount object to cents.
     */
    private function parsePayPalAmount(?array $amount): int
    {
        if (!$amount) {
            return 0;
        }

        $value = $amount['value'] ?? $amount['total'] ?? 0;

        return \LaravelPlus\PaymentGateway\Support\Money::toMinor($value, $amount['currency_code'] ?? $amount['currency'] ?? 'USD');
    }

    /**
     * Find the local user for a PayPal payer id, or for the custom_id the subscription was created with.
     */
    private function findUser(?string $paypalId): ?object
    {
        if (!$paypalId) {
            return null;
        }

        return PaymentCustomer::where('paypal_id', $paypalId)->first()?->user;
    }
}

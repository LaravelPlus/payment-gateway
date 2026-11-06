<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Listeners;

use Stripe\StripeClient;
use LaravelPlus\PaymentGateway\Services\PaymentNotificationService;
use Illuminate\Support\Facades\Cache;
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
use LaravelPlus\PaymentGateway\Models\Refund;
use LaravelPlus\PaymentGateway\Models\Subscription;
use LaravelPlus\PaymentGateway\Models\Transaction;
use LaravelPlus\PaymentGateway\Services\InvoicePdfGenerator;
use LaravelPlus\PaymentGateway\Services\TrialLifecycle;

/**
 * Handle Stripe webhook events and update database accordingly.
 */
final class HandleStripeWebhook
{
    public function __construct(
        private readonly InvoicePdfGenerator $pdfGenerator,
        private readonly PaymentNotificationService $notifications,
        private readonly TrialLifecycle $trials,
    ) {}

    /**
     * Handle the event.
     */
    public function handle(PaymentWebhookReceived $event): void
    {
        if ($event->payload->driver !== 'stripe') {
            return;
        }

        $type = $event->payload->type;
        $data = $event->payload->data;

        Log::info('Processing Stripe webhook', ['type' => $type]);

        match ($type) {
            // Payment Intent events
            'payment_intent.succeeded' => $this->handlePaymentSucceeded($data),
            'payment_intent.payment_failed' => $this->handlePaymentFailed($data),

            // Charge events
            'charge.succeeded' => $this->handleChargeSucceeded($data),
            'charge.failed' => $this->handleChargeFailed($data),
            'charge.refunded' => $this->handleChargeRefunded($data),

            // Subscription events
            'customer.subscription.created' => $this->handleSubscriptionCreated($data),
            'customer.subscription.updated' => $this->handleSubscriptionUpdated($data),
            'customer.subscription.deleted' => $this->handleSubscriptionDeleted($data),
            'customer.subscription.trial_will_end' => $this->handleTrialWillEnd($data),

            // Invoice events
            'invoice.paid' => $this->handleInvoicePaid($data),
            'invoice.payment_failed' => $this->handleInvoicePaymentFailed($data),
            'invoice.finalized' => $this->handleInvoiceFinalized($data),

            // Dispute events
            'charge.dispute.created', 'charge.dispute.closed', 'radar.early_fraud_warning.created' => $this->handleDispute($data, $type),

            default => Log::debug('Unhandled Stripe webhook type', ['type' => $type]),
        };
    }

    private function handlePaymentSucceeded(array $data): void
    {
        $paymentIntent = $data;

        $transaction = Transaction::where('provider_id', $paymentIntent['id'])
            ->where('driver', 'stripe')
            ->first();

        if ($transaction) {
            $transaction->update([
                'status' => PaymentStatus::Succeeded->value,
                'provider_response' => $paymentIntent,
            ]);

            // Create invoice
            $invoice = Invoice::createFromTransaction($transaction);

            // Generate PDF
            $this->pdfGenerator->generate($invoice);

            event(new PaymentSucceeded($transaction->toDto()));
        }
    }

    private function handlePaymentFailed(array $data): void
    {
        $paymentIntent = $data;

        $transaction = Transaction::where('provider_id', $paymentIntent['id'])
            ->where('driver', 'stripe')
            ->first();

        if ($transaction) {
            $failureMessage = $paymentIntent['last_payment_error']['message'] ?? 'Payment failed';

            $transaction->update([
                'status' => PaymentStatus::Failed->value,
                'failure_message' => $failureMessage,
                'provider_response' => $paymentIntent,
            ]);

            event(new PaymentFailed($transaction->toDto(), $failureMessage));
        }
    }

    private function handleChargeSucceeded(array $data): void
    {
        $charge = $data;

        // Find or create transaction
        $transaction = Transaction::firstOrCreate(
            ['provider_id' => $charge['id'], 'driver' => 'stripe'],
            [
                'user_id' => $this->findUserByCustomer($charge['customer'] ?? null)?->id,
                'amount' => $charge['amount'],
                'currency' => mb_strtoupper($charge['currency']),
                'status' => PaymentStatus::Succeeded->value,
                'payment_method' => $charge['payment_method'] ?? null,
                'description' => $charge['description'] ?? null,
                'provider_response' => $charge,
            ]
        );

        if ($transaction->wasRecentlyCreated) {
            event(new PaymentSucceeded($transaction->toDto()));
        }
    }

    private function handleChargeFailed(array $data): void
    {
        $charge = $data;

        $transaction = Transaction::where('provider_id', $charge['id'])
            ->where('driver', 'stripe')
            ->first();

        if ($transaction) {
            $transaction->update([
                'status' => PaymentStatus::Failed->value,
                'failure_message' => $charge['failure_message'] ?? 'Charge failed',
            ]);
        }
    }

    private function handleChargeRefunded(array $data): void
    {
        $charge = $data;

        // A charge from before webhooks were enabled has no transaction yet.
        $transaction = Transaction::firstOrCreate(
            ['provider_id' => $charge['id'], 'driver' => 'stripe'],
            [
                'user_id' => $this->findUserByCustomer($charge['customer'] ?? null)?->id,
                'amount' => $charge['amount'],
                'currency' => mb_strtoupper($charge['currency']),
                'status' => PaymentStatus::Succeeded->value,
                'payment_method' => $charge['payment_method'] ?? null,
                'description' => $charge['description'] ?? null,
                'provider_response' => $charge,
            ]
        );

        // Create refund records for each refund
        foreach ($charge['refunds']['data'] ?? $this->fetchRefunds($charge['id']) as $stripeRefund) {
            $refund = Refund::firstOrCreate(
                ['provider_id' => $stripeRefund['id'], 'driver' => 'stripe'],
                [
                    'transaction_id' => $transaction->id,
                    'user_id' => $transaction->user_id,
                    'amount' => $stripeRefund['amount'],
                    'currency' => mb_strtoupper($stripeRefund['currency']),
                    'status' => $stripeRefund['status'],
                    'reason' => $stripeRefund['reason'],
                    'provider_response' => $stripeRefund,
                ]
            );

            if ($refund->wasRecentlyCreated) {
                event(new RefundProcessed($refund->toDto()));
            }
        }

        // Update transaction status if fully refunded
        if ($charge['refunded']) {
            $transaction->update(['status' => PaymentStatus::Refunded->value]);
        }
    }

    /**
     * Stripe API 2022-11-15 and later leave refunds out of the charge event.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchRefunds(string $chargeId): array
    {
        $stripe = app(StripeClient::class, ['config' => (string) config('payment-gateway.drivers.stripe.secret')]);

        return array_map(
            static fn ($refund): array => $refund->toArray(),
            $stripe->refunds->all(['charge' => $chargeId, 'limit' => 100])->data,
        );
    }

    private function handleSubscriptionCreated(array $data): void
    {
        $stripeSubscription = $data;

        $subscription = $this->syncSubscription($stripeSubscription);

        if ($subscription->wasRecentlyCreated) {
            event(new SubscriptionCreated($subscription->toDto()));
        }
    }

    private function handleSubscriptionUpdated(array $data): void
    {
        $stripeSubscription = $data;

        $this->syncSubscription($stripeSubscription);
    }

    private function handleSubscriptionDeleted(array $data): void
    {
        $stripeSubscription = $data;

        $subscription = Subscription::where('provider_id', $stripeSubscription['id'])
            ->where('driver', 'stripe')
            ->first();

        if ($subscription) {
            $before = $subscription->status;

            $subscription->update([
                'status' => SubscriptionStatus::Canceled->value,
                'canceled_at' => now(),
                'ended_at' => now(),
            ]);

            event(new SubscriptionCanceled($subscription->toDto()));
            $this->trials->transition($before, $subscription);
        }
    }

    private function handleTrialWillEnd(array $data): void
    {
        $stripeSubscription = $data;

        $subscription = Subscription::where('provider_id', $stripeSubscription['id'])
            ->where('driver', 'stripe')
            ->first();

        if ($subscription) {
            // Stripe sends this 3 days out (at once for shorter trials). It shares the
            // scheduler's reminder ledger, so the customer is reminded once per lead.
            $this->trials->remind($subscription);
        }
    }

    private function handleInvoicePaid(array $data): void
    {
        $stripeInvoice = $data;

        // Update or create local invoice
        $invoice = Invoice::updateOrCreate(
            ['provider_id' => $stripeInvoice['id'], 'driver' => 'stripe'],
            [
                'user_id' => $this->findUserByCustomer($stripeInvoice['customer'])?->id,
                'status' => 'paid',
                'subtotal' => $stripeInvoice['subtotal'],
                'tax' => $stripeInvoice['tax'] ?? 0,
                'total' => $stripeInvoice['total'],
                'amount_paid' => $stripeInvoice['amount_paid'],
                'amount_due' => $stripeInvoice['amount_due'],
                'currency' => mb_strtoupper($stripeInvoice['currency']),
                'invoice_date' => now(),
                'paid_at' => now(),
                'line_items' => $this->mapStripeLineItems($stripeInvoice['lines']['data'] ?? []),
                'provider_response' => $stripeInvoice,
            ]
        );

        // Generate PDF
        if (!$invoice->hasPdf()) {
            $this->pdfGenerator->generate($invoice);
        }
    }

    private function handleInvoicePaymentFailed(array $data): void
    {
        $stripeInvoice = $data;

        Invoice::where('provider_id', $stripeInvoice['id'])
            ->where('driver', 'stripe')
            ->update(['status' => 'open']);

        $user = $this->findUserByCustomer($stripeInvoice['customer'] ?? null);

        // Stripe retries a failed invoice; the customer hears about it once.
        if ($user?->email && Cache::add('payment-gateway:invoice-failed:'.$stripeInvoice['id'], true, now()->addDays(60))) {
            $this->notifications->sendInvoicePaymentFailed(
                $user,
                (int) ($stripeInvoice['amount_due'] ?? 0),
                (string) ($stripeInvoice['currency'] ?? ''),
                $stripeInvoice['hosted_invoice_url'] ?? null,
            );
        }
    }

    private function handleDispute(array $dispute, string $type): void
    {
        Log::critical('Stripe dispute', [
            'type' => $type,
            'dispute_id' => $dispute['id'] ?? null,
            'charge_id' => $dispute['charge'] ?? null,
            'amount' => $dispute['amount'] ?? null,
            'currency' => $dispute['currency'] ?? null,
            'reason' => $dispute['reason'] ?? $dispute['fraud_type'] ?? null,
            'status' => $dispute['status'] ?? null,
        ]);

        $this->notifications->sendDisputeAlert($type, $dispute);
    }

    private function handleInvoiceFinalized(array $data): void
    {
        $stripeInvoice = $data;

        Invoice::where('provider_id', $stripeInvoice['id'])
            ->where('driver', 'stripe')
            ->update(['status' => 'open']);
    }

    /**
     * Sync subscription from Stripe data.
     */
    private function syncSubscription(array $stripeSubscription): Subscription
    {
        $user = $this->findUserByCustomer($stripeSubscription['customer']);

        $subscription = Subscription::firstOrNew(['provider_id' => $stripeSubscription['id'], 'driver' => 'stripe']);
        $before = $subscription->status;

        // Required on create; never overwritten, because the app may have set it.
        if (! $subscription->exists) {
            $subscription->plan_id = $stripeSubscription['metadata']['plan']
                ?? $stripeSubscription['items']['data'][0]['price']['id']
                ?? 'unknown';
        }

        $subscription->fill(
            [
                'user_id' => $user?->id,
                'provider_plan_id' => $stripeSubscription['items']['data'][0]['price']['id'] ?? null,
                'status' => $this->mapStripeStatus($stripeSubscription['status']),
                'amount' => $stripeSubscription['items']['data'][0]['price']['unit_amount'] ?? 0,
                'currency' => mb_strtoupper($stripeSubscription['currency']),
                'interval' => $stripeSubscription['items']['data'][0]['price']['recurring']['interval'] ?? 'month',
                'interval_count' => $stripeSubscription['items']['data'][0]['price']['recurring']['interval_count'] ?? 1,
                'quantity' => $stripeSubscription['quantity'] ?? 1,
                'current_period_start' => $this->periodTimestamp($stripeSubscription, 'current_period_start'),
                'current_period_end' => $this->periodTimestamp($stripeSubscription, 'current_period_end'),
                'trial_start' => isset($stripeSubscription['trial_start'])
                    ? \Carbon\Carbon::createFromTimestamp($stripeSubscription['trial_start'])
                    : null,
                'trial_end' => isset($stripeSubscription['trial_end'])
                    ? \Carbon\Carbon::createFromTimestamp($stripeSubscription['trial_end'])
                    : null,
                'canceled_at' => isset($stripeSubscription['canceled_at'])
                    ? \Carbon\Carbon::createFromTimestamp($stripeSubscription['canceled_at'])
                    : null,
                'cancel_at_period_end' => $stripeSubscription['cancel_at_period_end'] ?? false,
                'provider_response' => $stripeSubscription,
            ]
        )->save();

        if ($before !== $subscription->status) {
            $this->trials->transition($before, $subscription);
        }

        return $subscription;
    }

    /**
     * Stripe API 2025-03-31 and later keep the period on the subscription item.
     */
    private function periodTimestamp(array $stripeSubscription, string $field): ?\Carbon\Carbon
    {
        $timestamp = $stripeSubscription[$field] ?? $stripeSubscription['items']['data'][0][$field] ?? null;

        return $timestamp !== null ? \Carbon\Carbon::createFromTimestamp($timestamp) : null;
    }

    /**
     * Map Stripe subscription status to our status.
     */
    private function mapStripeStatus(string $status): string
    {
        return match ($status) {
            'active' => SubscriptionStatus::Active->value,
            'trialing' => SubscriptionStatus::Trialing->value,
            'past_due' => SubscriptionStatus::PastDue->value,
            'canceled' => SubscriptionStatus::Canceled->value,
            'unpaid' => SubscriptionStatus::Unpaid->value,
            'incomplete' => SubscriptionStatus::Incomplete->value,
            'incomplete_expired' => SubscriptionStatus::Expired->value,
            'paused' => SubscriptionStatus::Paused->value,
            default => SubscriptionStatus::Incomplete->value,
        };
    }

    /**
     * Find user by Stripe customer ID.
     */
    private function findUserByCustomer(?string $customerId): ?object
    {
        if (!$customerId) {
            return null;
        }

        $paymentCustomer = \LaravelPlus\PaymentGateway\Models\PaymentCustomer::where('stripe_id', $customerId)
            ->first();

        // Billable::createAsPaymentCustomer() stores the ID on users.stripe_id, not in payment_customers.
        return $paymentCustomer?->user
            ?? config('auth.providers.users.model')::where('stripe_id', $customerId)->first();
    }

    /**
     * Map Stripe line items to our format.
     *
     * @return array<array<string, mixed>>
     */
    private function mapStripeLineItems(array $lines): array
    {
        return collect($lines)->map(fn ($line) => [
            'description' => $line['description'] ?? 'Subscription',
            'quantity' => $line['quantity'] ?? 1,
            'unit_price' => $line['price']['unit_amount'] ?? $line['amount'],
            'amount' => $line['amount'],
        ])->toArray();
    }
}

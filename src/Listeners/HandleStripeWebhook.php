<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Listeners;

use Illuminate\Support\Facades\Log;
use Nejcc\PaymentGateway\Enums\PaymentStatus;
use Nejcc\PaymentGateway\Enums\SubscriptionStatus;
use Nejcc\PaymentGateway\Events\PaymentFailed;
use Nejcc\PaymentGateway\Events\PaymentSucceeded;
use Nejcc\PaymentGateway\Events\PaymentWebhookReceived;
use Nejcc\PaymentGateway\Events\RefundProcessed;
use Nejcc\PaymentGateway\Events\SubscriptionCanceled;
use Nejcc\PaymentGateway\Events\SubscriptionCreated;
use Nejcc\PaymentGateway\Models\Invoice;
use Nejcc\PaymentGateway\Models\Refund;
use Nejcc\PaymentGateway\Models\Subscription;
use Nejcc\PaymentGateway\Models\Transaction;
use Nejcc\PaymentGateway\Services\InvoicePdfGenerator;

/**
 * Handle Stripe webhook events and update database accordingly.
 */
final class HandleStripeWebhook
{
    public function __construct(
        private readonly InvoicePdfGenerator $pdfGenerator,
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

            default => Log::debug('Unhandled Stripe webhook type', ['type' => $type]),
        };
    }

    private function handlePaymentSucceeded(array $data): void
    {
        $paymentIntent = $data['object'] ?? $data;

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

            event(new PaymentSucceeded($transaction->toPaymentResult()));
        }
    }

    private function handlePaymentFailed(array $data): void
    {
        $paymentIntent = $data['object'] ?? $data;

        $transaction = Transaction::where('provider_id', $paymentIntent['id'])
            ->where('driver', 'stripe')
            ->first();

        if ($transaction) {
            $failureMessage = $paymentIntent['last_payment_error']['message'] ?? 'Payment failed';

            $transaction->update([
                'status' => PaymentStatus::Failed->value,
                'failure_reason' => $failureMessage,
                'provider_response' => $paymentIntent,
            ]);

            event(new PaymentFailed($transaction->toPaymentResult(), $failureMessage));
        }
    }

    private function handleChargeSucceeded(array $data): void
    {
        $charge = $data['object'] ?? $data;

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
            event(new PaymentSucceeded($transaction->toPaymentResult()));
        }
    }

    private function handleChargeFailed(array $data): void
    {
        $charge = $data['object'] ?? $data;

        $transaction = Transaction::where('provider_id', $charge['id'])
            ->where('driver', 'stripe')
            ->first();

        if ($transaction) {
            $transaction->update([
                'status' => PaymentStatus::Failed->value,
                'failure_reason' => $charge['failure_message'] ?? 'Charge failed',
            ]);
        }
    }

    private function handleChargeRefunded(array $data): void
    {
        $charge = $data['object'] ?? $data;

        $transaction = Transaction::where('provider_id', $charge['id'])
            ->where('driver', 'stripe')
            ->first();

        if (!$transaction) {
            return;
        }

        // Create refund records for each refund
        foreach ($charge['refunds']['data'] ?? [] as $stripeRefund) {
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

    private function handleSubscriptionCreated(array $data): void
    {
        $stripeSubscription = $data['object'] ?? $data;

        $subscription = $this->syncSubscription($stripeSubscription);

        if ($subscription->wasRecentlyCreated) {
            event(new SubscriptionCreated($subscription->toDto()));
        }
    }

    private function handleSubscriptionUpdated(array $data): void
    {
        $stripeSubscription = $data['object'] ?? $data;

        $this->syncSubscription($stripeSubscription);
    }

    private function handleSubscriptionDeleted(array $data): void
    {
        $stripeSubscription = $data['object'] ?? $data;

        $subscription = Subscription::where('provider_id', $stripeSubscription['id'])
            ->where('driver', 'stripe')
            ->first();

        if ($subscription) {
            $subscription->update([
                'status' => SubscriptionStatus::Canceled->value,
                'canceled_at' => now(),
                'ended_at' => now(),
            ]);

            event(new SubscriptionCanceled($subscription->toDto()));
        }
    }

    private function handleTrialWillEnd(array $data): void
    {
        $stripeSubscription = $data['object'] ?? $data;

        $subscription = Subscription::where('provider_id', $stripeSubscription['id'])
            ->where('driver', 'stripe')
            ->first();

        if ($subscription) {
            // You could dispatch a notification here
            Log::info('Trial ending soon', [
                'subscription_id' => $subscription->id,
                'trial_end' => $subscription->trial_end,
            ]);
        }
    }

    private function handleInvoicePaid(array $data): void
    {
        $stripeInvoice = $data['object'] ?? $data;

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
        $stripeInvoice = $data['object'] ?? $data;

        Invoice::where('provider_id', $stripeInvoice['id'])
            ->where('driver', 'stripe')
            ->update(['status' => 'open']);
    }

    private function handleInvoiceFinalized(array $data): void
    {
        $stripeInvoice = $data['object'] ?? $data;

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

        return Subscription::updateOrCreate(
            ['provider_id' => $stripeSubscription['id'], 'driver' => 'stripe'],
            [
                'user_id' => $user?->id,
                'provider_plan_id' => $stripeSubscription['items']['data'][0]['price']['id'] ?? null,
                'status' => $this->mapStripeStatus($stripeSubscription['status']),
                'amount' => $stripeSubscription['items']['data'][0]['price']['unit_amount'] ?? 0,
                'currency' => mb_strtoupper($stripeSubscription['currency']),
                'interval' => $stripeSubscription['items']['data'][0]['price']['recurring']['interval'] ?? 'month',
                'interval_count' => $stripeSubscription['items']['data'][0]['price']['recurring']['interval_count'] ?? 1,
                'quantity' => $stripeSubscription['quantity'] ?? 1,
                'current_period_start' => isset($stripeSubscription['current_period_start'])
                    ? \Carbon\Carbon::createFromTimestamp($stripeSubscription['current_period_start'])
                    : null,
                'current_period_end' => isset($stripeSubscription['current_period_end'])
                    ? \Carbon\Carbon::createFromTimestamp($stripeSubscription['current_period_end'])
                    : null,
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
        );
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

        $userModel = config('payment-gateway.billable_model', 'App\\Models\\User');

        return $userModel::whereHas('paymentCustomers', function ($query) use ($customerId): void {
            $query->where('provider_id', $customerId)->where('driver', 'stripe');
        })->first();
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

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
 * Handle PayPal webhook events and update database accordingly.
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
            'CHECKOUT.ORDER.APPROVED' => $this->handleOrderApproved($data),
            'CHECKOUT.ORDER.COMPLETED' => $this->handleOrderCompleted($data),

            // Subscription events
            'BILLING.SUBSCRIPTION.CREATED' => $this->handleSubscriptionCreated($data),
            'BILLING.SUBSCRIPTION.ACTIVATED' => $this->handleSubscriptionActivated($data),
            'BILLING.SUBSCRIPTION.UPDATED' => $this->handleSubscriptionUpdated($data),
            'BILLING.SUBSCRIPTION.CANCELLED' => $this->handleSubscriptionCancelled($data),
            'BILLING.SUBSCRIPTION.SUSPENDED' => $this->handleSubscriptionSuspended($data),
            'BILLING.SUBSCRIPTION.EXPIRED' => $this->handleSubscriptionExpired($data),

            // Payment sale events (for subscriptions)
            'PAYMENT.SALE.COMPLETED' => $this->handleSaleCompleted($data),
            'PAYMENT.SALE.REFUNDED' => $this->handleSaleRefunded($data),

            default => Log::debug('Unhandled PayPal webhook type', ['type' => $type]),
        };
    }

    private function handlePaymentCompleted(array $data): void
    {
        $captureId = $data['id'] ?? null;
        if (!$captureId) {
            return;
        }

        $transaction = Transaction::where('provider_id', $captureId)
            ->where('driver', 'paypal')
            ->first();

        if ($transaction) {
            $transaction->update([
                'status' => PaymentStatus::Succeeded->value,
                'provider_response' => $data,
            ]);

            // Create invoice
            $invoice = Invoice::createFromTransaction($transaction);
            $this->pdfGenerator->generate($invoice);

            event(new PaymentSucceeded($transaction->toPaymentResult()));
        }
    }

    private function handlePaymentDenied(array $data): void
    {
        $captureId = $data['id'] ?? null;
        if (!$captureId) {
            return;
        }

        $transaction = Transaction::where('provider_id', $captureId)
            ->where('driver', 'paypal')
            ->first();

        if ($transaction) {
            $failureMessage = $data['status_details']['reason'] ?? 'Payment denied';

            $transaction->update([
                'status' => PaymentStatus::Failed->value,
                'failure_reason' => $failureMessage,
                'provider_response' => $data,
            ]);

            event(new PaymentFailed($transaction->toPaymentResult(), $failureMessage));
        }
    }

    private function handlePaymentRefunded(array $data): void
    {
        $captureId = $data['id'] ?? null;
        if (!$captureId) {
            return;
        }

        $transaction = Transaction::where('provider_id', $captureId)
            ->where('driver', 'paypal')
            ->first();

        if (!$transaction) {
            return;
        }

        // Create refund record
        $refundData = $data['refund'] ?? $data;
        $refundId = $refundData['id'] ?? $captureId.'-refund';

        $refund = Refund::firstOrCreate(
            ['provider_id' => $refundId, 'driver' => 'paypal'],
            [
                'transaction_id' => $transaction->id,
                'user_id' => $transaction->user_id,
                'amount' => $this->parsePayPalAmount($refundData['amount'] ?? $data['amount'] ?? null),
                'currency' => mb_strtoupper($refundData['amount']['currency_code'] ?? $transaction->currency),
                'status' => 'completed',
                'reason' => $refundData['note_to_payer'] ?? null,
                'provider_response' => $data,
            ]
        );

        if ($refund->wasRecentlyCreated) {
            event(new RefundProcessed($refund->toDto()));
        }

        // Update transaction status
        $transaction->update(['status' => PaymentStatus::Refunded->value]);
    }

    private function handleOrderApproved(array $data): void
    {
        Log::info('PayPal order approved', ['order_id' => $data['id'] ?? null]);
    }

    private function handleOrderCompleted(array $data): void
    {
        $orderId = $data['id'] ?? null;
        if (!$orderId) {
            return;
        }

        // Find transaction by order ID
        $transaction = Transaction::where('provider_id', $orderId)
            ->where('driver', 'paypal')
            ->first();

        if ($transaction && $transaction->status !== PaymentStatus::Succeeded->value) {
            $transaction->update([
                'status' => PaymentStatus::Succeeded->value,
                'provider_response' => $data,
            ]);

            // Create invoice
            $invoice = Invoice::createFromTransaction($transaction);
            $this->pdfGenerator->generate($invoice);

            event(new PaymentSucceeded($transaction->toPaymentResult()));
        }
    }

    private function handleSubscriptionCreated(array $data): void
    {
        $subscription = $this->syncSubscription($data);

        if ($subscription->wasRecentlyCreated) {
            event(new SubscriptionCreated($subscription->toDto()));
        }
    }

    private function handleSubscriptionActivated(array $data): void
    {
        $subscription = Subscription::where('provider_id', $data['id'])
            ->where('driver', 'paypal')
            ->first();

        if ($subscription) {
            $subscription->update([
                'status' => SubscriptionStatus::Active->value,
                'provider_response' => $data,
            ]);
        }
    }

    private function handleSubscriptionUpdated(array $data): void
    {
        $this->syncSubscription($data);
    }

    private function handleSubscriptionCancelled(array $data): void
    {
        $subscription = Subscription::where('provider_id', $data['id'])
            ->where('driver', 'paypal')
            ->first();

        if ($subscription) {
            $subscription->update([
                'status' => SubscriptionStatus::Canceled->value,
                'canceled_at' => now(),
                'ended_at' => now(),
                'provider_response' => $data,
            ]);

            event(new SubscriptionCanceled($subscription->toDto()));
        }
    }

    private function handleSubscriptionSuspended(array $data): void
    {
        $subscription = Subscription::where('provider_id', $data['id'])
            ->where('driver', 'paypal')
            ->first();

        if ($subscription) {
            $subscription->update([
                'status' => SubscriptionStatus::Paused->value,
                'provider_response' => $data,
            ]);
        }
    }

    private function handleSubscriptionExpired(array $data): void
    {
        $subscription = Subscription::where('provider_id', $data['id'])
            ->where('driver', 'paypal')
            ->first();

        if ($subscription) {
            $subscription->update([
                'status' => SubscriptionStatus::Expired->value,
                'ended_at' => now(),
                'provider_response' => $data,
            ]);
        }
    }

    private function handleSaleCompleted(array $data): void
    {
        $saleId = $data['id'] ?? null;
        $subscriptionId = $data['billing_agreement_id'] ?? null;

        if (!$saleId) {
            return;
        }

        // Create transaction for subscription payment
        $subscription = $subscriptionId
            ? Subscription::where('provider_id', $subscriptionId)->where('driver', 'paypal')->first()
            : null;

        $transaction = Transaction::firstOrCreate(
            ['provider_id' => $saleId, 'driver' => 'paypal'],
            [
                'user_id' => $subscription?->user_id,
                'subscription_id' => $subscription?->id,
                'amount' => $this->parsePayPalAmount($data['amount'] ?? null),
                'currency' => mb_strtoupper($data['amount']['currency'] ?? 'USD'),
                'status' => PaymentStatus::Succeeded->value,
                'description' => 'Subscription payment',
                'provider_response' => $data,
            ]
        );

        if ($transaction->wasRecentlyCreated) {
            // Create invoice
            $invoice = Invoice::createFromTransaction($transaction);
            $this->pdfGenerator->generate($invoice);

            event(new PaymentSucceeded($transaction->toPaymentResult()));
        }
    }

    private function handleSaleRefunded(array $data): void
    {
        $saleId = $data['sale_id'] ?? $data['id'] ?? null;
        if (!$saleId) {
            return;
        }

        $transaction = Transaction::where('provider_id', $saleId)
            ->where('driver', 'paypal')
            ->first();

        if (!$transaction) {
            return;
        }

        $refund = Refund::firstOrCreate(
            ['provider_id' => $data['id'], 'driver' => 'paypal'],
            [
                'transaction_id' => $transaction->id,
                'user_id' => $transaction->user_id,
                'amount' => $this->parsePayPalAmount($data['amount'] ?? null),
                'currency' => mb_strtoupper($data['amount']['currency'] ?? $transaction->currency),
                'status' => 'completed',
                'provider_response' => $data,
            ]
        );

        if ($refund->wasRecentlyCreated) {
            event(new RefundProcessed($refund->toDto()));
        }
    }

    /**
     * Sync subscription from PayPal data.
     */
    private function syncSubscription(array $data): Subscription
    {
        $user = $this->findUserByPayerId($data['subscriber']['payer_id'] ?? null);

        $billingInfo = $data['billing_info'] ?? [];
        $startTime = $data['start_time'] ?? null;
        $nextBillingTime = $billingInfo['next_billing_time'] ?? null;

        return Subscription::updateOrCreate(
            ['provider_id' => $data['id'], 'driver' => 'paypal'],
            [
                'user_id' => $user?->id,
                'provider_plan_id' => $data['plan_id'] ?? null,
                'status' => $this->mapPayPalStatus($data['status'] ?? 'APPROVAL_PENDING'),
                'amount' => $this->parsePayPalAmount($billingInfo['last_payment']['amount'] ?? null),
                'currency' => mb_strtoupper($billingInfo['last_payment']['amount']['currency_code'] ?? 'USD'),
                'quantity' => $data['quantity'] ?? 1,
                'current_period_start' => $startTime ? \Carbon\Carbon::parse($startTime) : null,
                'current_period_end' => $nextBillingTime ? \Carbon\Carbon::parse($nextBillingTime) : null,
                'provider_response' => $data,
            ]
        );
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

        return (int) round((float) $value * 100);
    }

    /**
     * Find user by PayPal payer ID.
     */
    private function findUserByPayerId(?string $payerId): ?object
    {
        if (!$payerId) {
            return null;
        }

        $userModel = config('payment-gateway.billable_model', 'App\\Models\\User');

        return $userModel::whereHas('paymentCustomers', function ($query) use ($payerId): void {
            $query->where('provider_id', $payerId)->where('driver', 'paypal');
        })->first();
    }
}

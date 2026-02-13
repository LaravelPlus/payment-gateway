<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Listeners;

use LaravelPlus\PaymentGateway\Events\PaymentFailed;
use LaravelPlus\PaymentGateway\Events\PaymentSucceeded;
use LaravelPlus\PaymentGateway\Events\RefundProcessed;
use LaravelPlus\PaymentGateway\Events\SubscriptionCanceled;
use LaravelPlus\PaymentGateway\Events\SubscriptionCreated;
use LaravelPlus\PaymentGateway\Models\Invoice;
use LaravelPlus\PaymentGateway\Models\Refund;
use LaravelPlus\PaymentGateway\Models\Subscription;
use LaravelPlus\PaymentGateway\Models\Transaction;
use LaravelPlus\PaymentGateway\Services\PaymentNotificationService;

/**
 * Event subscriber for sending payment notifications.
 */
final class SendPaymentNotifications
{
    public function __construct(
        private readonly PaymentNotificationService $notificationService,
    ) {}

    /**
     * Handle payment succeeded events.
     */
    public function handlePaymentSucceeded(PaymentSucceeded $event): void
    {
        $transaction = Transaction::where('provider_id', $event->result->transactionId)->first();

        if ($transaction) {
            $invoice = Invoice::where('transaction_id', $transaction->id)->first();
            $this->notificationService->sendPaymentReceipt($transaction, $invoice);
        }
    }

    /**
     * Handle payment failed events.
     */
    public function handlePaymentFailed(PaymentFailed $event): void
    {
        $transaction = Transaction::where('provider_id', $event->result->transactionId)->first();

        if ($transaction) {
            $this->notificationService->sendPaymentFailed($transaction, $event->reason);
        }
    }

    /**
     * Handle subscription created events.
     */
    public function handleSubscriptionCreated(SubscriptionCreated $event): void
    {
        $subscription = Subscription::where('provider_id', $event->subscription->providerId)->first();

        if ($subscription) {
            $this->notificationService->sendSubscriptionCreated($subscription);
        }
    }

    /**
     * Handle subscription canceled events.
     */
    public function handleSubscriptionCanceled(SubscriptionCanceled $event): void
    {
        $subscription = Subscription::where('provider_id', $event->subscription->providerId)->first();

        if ($subscription) {
            $this->notificationService->sendSubscriptionCanceled($subscription);
        }
    }

    /**
     * Handle refund processed events.
     */
    public function handleRefundProcessed(RefundProcessed $event): void
    {
        $refund = Refund::where('provider_id', $event->refund->providerId)->first();

        if ($refund) {
            $this->notificationService->sendRefundProcessed($refund);
        }
    }

    /**
     * Register the listeners for the subscriber.
     *
     * @return array<string, string>
     */
    public function subscribe(): array
    {
        return [
            PaymentSucceeded::class => 'handlePaymentSucceeded',
            PaymentFailed::class => 'handlePaymentFailed',
            SubscriptionCreated::class => 'handleSubscriptionCreated',
            SubscriptionCanceled::class => 'handleSubscriptionCanceled',
            RefundProcessed::class => 'handleRefundProcessed',
        ];
    }
}

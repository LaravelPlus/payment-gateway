<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Services;

use Illuminate\Support\Facades\Mail;
use Nejcc\PaymentGateway\Mail\PaymentFailedMail;
use Nejcc\PaymentGateway\Mail\PaymentReceiptMail;
use Nejcc\PaymentGateway\Mail\RefundProcessedMail;
use Nejcc\PaymentGateway\Mail\SubscriptionCanceledMail;
use Nejcc\PaymentGateway\Mail\SubscriptionCreatedMail;
use Nejcc\PaymentGateway\Mail\SubscriptionTrialEndingMail;
use Nejcc\PaymentGateway\Models\Invoice;
use Nejcc\PaymentGateway\Models\Refund;
use Nejcc\PaymentGateway\Models\Subscription;
use Nejcc\PaymentGateway\Models\Transaction;

/**
 * Service for sending payment-related notifications.
 */
final class PaymentNotificationService
{
    /**
     * Send payment receipt email.
     */
    public function sendPaymentReceipt(Transaction $transaction, ?Invoice $invoice = null): void
    {
        if (!$this->isNotificationEnabled('payment_receipt')) {
            return;
        }

        $user = $transaction->user;
        if (!$user || !$user->email) {
            return;
        }

        Mail::to($user->email)->queue(new PaymentReceiptMail($transaction, $invoice));
    }

    /**
     * Send payment failed email.
     */
    public function sendPaymentFailed(Transaction $transaction, string $reason): void
    {
        if (!$this->isNotificationEnabled('payment_failed')) {
            return;
        }

        $user = $transaction->user;
        if (!$user || !$user->email) {
            return;
        }

        Mail::to($user->email)->queue(new PaymentFailedMail($transaction, $reason));
    }

    /**
     * Send subscription created email.
     */
    public function sendSubscriptionCreated(Subscription $subscription): void
    {
        if (!$this->isNotificationEnabled('subscription_created')) {
            return;
        }

        $user = $subscription->user;
        if (!$user || !$user->email) {
            return;
        }

        Mail::to($user->email)->queue(new SubscriptionCreatedMail($subscription));
    }

    /**
     * Send subscription canceled email.
     */
    public function sendSubscriptionCanceled(Subscription $subscription): void
    {
        if (!$this->isNotificationEnabled('subscription_canceled')) {
            return;
        }

        $user = $subscription->user;
        if (!$user || !$user->email) {
            return;
        }

        Mail::to($user->email)->queue(new SubscriptionCanceledMail($subscription));
    }

    /**
     * Send refund processed email.
     */
    public function sendRefundProcessed(Refund $refund): void
    {
        if (!$this->isNotificationEnabled('refund_processed')) {
            return;
        }

        $user = $refund->user;
        if (!$user || !$user->email) {
            return;
        }

        Mail::to($user->email)->queue(new RefundProcessedMail($refund));
    }

    /**
     * Send trial ending reminder email.
     */
    public function sendTrialEndingReminder(Subscription $subscription, int $daysRemaining): void
    {
        if (!$this->isNotificationEnabled('trial_ending')) {
            return;
        }

        $user = $subscription->user;
        if (!$user || !$user->email) {
            return;
        }

        Mail::to($user->email)->queue(new SubscriptionTrialEndingMail($subscription, $daysRemaining));
    }

    /**
     * Check if a notification type is enabled.
     */
    private function isNotificationEnabled(string $type): bool
    {
        return config("payment-gateway.notifications.{$type}", true);
    }
}

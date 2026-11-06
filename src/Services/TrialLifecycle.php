<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Services;

use Illuminate\Support\Facades\DB;
use LaravelPlus\PaymentGateway\Enums\SubscriptionStatus;
use LaravelPlus\PaymentGateway\Events\SubscriptionTrialEnded;
use LaravelPlus\PaymentGateway\Events\SubscriptionTrialStarted;
use LaravelPlus\PaymentGateway\Events\SubscriptionTrialWillEnd;
use LaravelPlus\PaymentGateway\Models\Subscription;

/**
 * Turns trial state changes into host-app events, each exactly once.
 *
 * "Once" is enforced by claiming a key in payment_webhook_events, the same atomic
 * insertOrIgnore ledger the webhook controller uses, so a redelivered webhook, an
 * out-of-order update or a retried scheduler run cannot fire an event twice.
 */
final class TrialLifecycle
{
    /**
     * Fire trial started/ended for a subscription whose status just changed.
     */
    public function transition(?string $before, Subscription $subscription): void
    {
        $trialing = SubscriptionStatus::Trialing->value;

        if ($subscription->status === $trialing) {
            if ($this->claim('trial-started:'.$subscription->getKey())) {
                event(new SubscriptionTrialStarted($subscription->toDto()));
            }

            return;
        }

        if ($before === $trialing && $this->claim('trial-ended:'.$subscription->getKey())) {
            event(new SubscriptionTrialEnded($subscription->toDto()));
        }
    }

    /**
     * Send the reminder that is due now, if any.
     *
     * A reminder for lead L is due once the trial has L days or less left. Only the
     * smallest due lead is ever claimed, so when several are due at once (a 1-day
     * trial with leads [3, 1], or a scheduler that was down) the customer gets one
     * reminder, never a stale "ends in 3 days". Keys include trial_end, so an
     * extended or shortened trial gets fresh reminders.
     *
     * @param  array<int>|null  $leads  days before trial end; defaults to notifications.trial_reminder_days
     */
    public function remind(Subscription $subscription, ?array $leads = null): bool
    {
        $leads = $leads ?? (array) config('payment-gateway.notifications.trial_reminder_days', [3, 1]);

        if ($subscription->status !== SubscriptionStatus::Trialing->value || $subscription->trial_end === null) {
            return false;
        }

        $secondsLeft = $subscription->trial_end->getTimestamp() - now()->getTimestamp();
        if ($secondsLeft <= 0) {
            return false;
        }

        $due = array_filter(array_map('intval', $leads), fn (int $d): bool => $d > 0 && $secondsLeft <= $d * 86400);

        if ($due === []) {
            return false;
        }

        $lead = min($due);
        $key = 'trial-reminder:'.$subscription->getKey().':'.$subscription->trial_end->getTimestamp().':'.$lead;

        // The claim rolls back if a listener throws, so the next run retries it.
        return DB::transaction(function () use ($key, $subscription, $secondsLeft): bool {
            if (!$this->claim($key)) {
                return false;
            }

            event(new SubscriptionTrialWillEnd($subscription->toDto(), (int) ceil($secondsLeft / 86400)));

            return true;
        });
    }

    private function claim(string $key): bool
    {
        return DB::table('payment_webhook_events')->insertOrIgnore(['id' => $key, 'created_at' => now()]) === 1;
    }
}

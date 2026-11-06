<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Console\Commands;

use Illuminate\Console\Command;
use LaravelPlus\PaymentGateway\Enums\SubscriptionStatus;
use LaravelPlus\PaymentGateway\Models\Subscription;
use LaravelPlus\PaymentGateway\Services\TrialLifecycle;

/**
 * Fires SubscriptionTrialWillEnd for every trial that has reached a reminder lead.
 * Safe to run as often as you like (hourly suits 1-day trials): each reminder is
 * claimed once, and a run that was missed is caught up by the next one.
 */
final class SendTrialEndingRemindersCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'payment-gateway:trial-reminders
                            {--days=* : Reminder leads in days (default: notifications.trial_reminder_days)}';

    /**
     * @var string
     */
    protected $description = 'Dispatch trial-ending reminders that are due';

    public function handle(TrialLifecycle $trials): int
    {
        $leads = $this->option('days') ?: (array) config('payment-gateway.notifications.trial_reminder_days', [3, 1]);
        $leads = array_filter(array_map('intval', $leads), fn (int $d): bool => $d > 0);
        if ($leads === []) {
            return self::SUCCESS;
        }

        $sent = 0;

        Subscription::query()
            ->where('status', SubscriptionStatus::Trialing->value)
            ->where('trial_end', '>', now())
            ->where('trial_end', '<=', now()->addDays(max($leads)))
            ->each(function (Subscription $subscription) use ($trials, $leads, &$sent): void {
                $sent += (int) $trials->remind($subscription, $leads);
            });

        $this->info("Dispatched {$sent} trial ending reminder(s).");

        return self::SUCCESS;
    }
}

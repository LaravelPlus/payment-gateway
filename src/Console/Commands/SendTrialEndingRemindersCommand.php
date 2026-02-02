<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Console\Commands;

use Illuminate\Console\Command;
use Nejcc\PaymentGateway\Enums\SubscriptionStatus;
use Nejcc\PaymentGateway\Models\Subscription;
use Nejcc\PaymentGateway\Services\PaymentNotificationService;

final class SendTrialEndingRemindersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payment-gateway:trial-reminders
                            {--days=3 : Number of days before trial ends to send reminder}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send reminder emails to users whose trial is ending soon';

    public function __construct(
        private readonly PaymentNotificationService $notificationService,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = (int) $this->option('days');
        $targetDate = now()->addDays($days)->startOfDay();

        $this->info("Looking for subscriptions with trials ending on {$targetDate->format('Y-m-d')}...");

        $subscriptions = Subscription::query()
            ->where('status', SubscriptionStatus::Trialing->value)
            ->whereNotNull('trial_end')
            ->whereDate('trial_end', $targetDate)
            ->with('user')
            ->get();

        if ($subscriptions->isEmpty()) {
            $this->info('No subscriptions found with trials ending in '.$days.' days.');

            return self::SUCCESS;
        }

        $this->info("Found {$subscriptions->count()} subscription(s) with trials ending in {$days} days.");

        $sent = 0;
        foreach ($subscriptions as $subscription) {
            if ($subscription->user && $subscription->user->email) {
                $this->notificationService->sendTrialEndingReminder($subscription, $days);
                $sent++;
                $this->line("  - Sent reminder to {$subscription->user->email}");
            }
        }

        $this->info("Sent {$sent} trial ending reminder(s).");

        return self::SUCCESS;
    }
}

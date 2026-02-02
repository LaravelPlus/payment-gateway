<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Console\Commands;

use Illuminate\Console\Command;
use Nejcc\PaymentGateway\Enums\SubscriptionStatus;
use Nejcc\PaymentGateway\Models\Subscription;

final class CleanupExpiredSubscriptionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payment-gateway:cleanup-subscriptions
                            {--dry-run : Show what would be updated without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Mark expired subscriptions and trials as expired';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Running in dry-run mode. No changes will be made.');
        }

        // Find subscriptions with expired trials
        $expiredTrials = Subscription::query()
            ->where('status', SubscriptionStatus::Trialing->value)
            ->whereNotNull('trial_end')
            ->where('trial_end', '<', now())
            ->get();

        $this->info("Found {$expiredTrials->count()} subscription(s) with expired trials.");

        if (!$dryRun && $expiredTrials->isNotEmpty()) {
            foreach ($expiredTrials as $subscription) {
                $subscription->update([
                    'status' => SubscriptionStatus::Expired->value,
                    'ended_at' => now(),
                ]);
                $this->line("  - Marked subscription #{$subscription->id} as expired (trial ended)");
            }
        }

        // Find subscriptions past their period end with no grace period
        $graceDays = config('payment-gateway.subscriptions.grace_period_days', 0);
        $expiredPeriods = Subscription::query()
            ->whereIn('status', [
                SubscriptionStatus::Active->value,
                SubscriptionStatus::PastDue->value,
            ])
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<', now()->subDays($graceDays))
            ->get();

        $this->info("Found {$expiredPeriods->count()} subscription(s) past their billing period.");

        if (!$dryRun && $expiredPeriods->isNotEmpty()) {
            foreach ($expiredPeriods as $subscription) {
                $subscription->update([
                    'status' => SubscriptionStatus::Expired->value,
                    'ended_at' => now(),
                ]);
                $this->line("  - Marked subscription #{$subscription->id} as expired (period ended)");
            }
        }

        $total = $expiredTrials->count() + $expiredPeriods->count();
        $this->info("Total: {$total} subscription(s) ".($dryRun ? 'would be' : 'were').' marked as expired.');

        return self::SUCCESS;
    }
}

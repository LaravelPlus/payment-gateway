<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Nejcc\PaymentGateway\Facades\Payment;
use Nejcc\PaymentGateway\Models\Plan;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\progress;
use function Laravel\Prompts\warning;

final class SyncPlansCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'payment-gateway:sync-plans
                            {--driver=stripe : Payment driver to sync with}
                            {--dry-run : Show what would be synced without making changes}';

    /**
     * The console command description.
     */
    protected $description = 'Sync subscription plans with payment provider (Stripe, PayPal)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $driver = $this->option('driver');
        $dryRun = $this->option('dry-run');

        info("Syncing plans with {$driver}...");

        if ($dryRun) {
            warning('DRY RUN - No changes will be made');
        }

        $plans = Plan::active()->paid()->get();

        if ($plans->isEmpty()) {
            warning('No active paid plans found to sync.');

            return self::SUCCESS;
        }

        $this->newLine();
        note("Found {$plans->count()} plans to sync");

        if (!confirm("Continue syncing {$plans->count()} plans to {$driver}?", true)) {
            return self::SUCCESS;
        }

        $progress = progress(label: 'Syncing plans', steps: $plans->count());
        $progress->start();

        $synced = 0;
        $errors = [];

        foreach ($plans as $plan) {
            try {
                if (!$dryRun) {
                    $this->syncPlan($plan, $driver);
                }
                $synced++;
                $progress->advance();
            } catch (Exception $e) {
                $errors[] = [
                    'plan' => $plan->name,
                    'error' => $e->getMessage(),
                ];
                $progress->advance();
            }
        }

        $progress->finish();

        $this->newLine();

        if ($synced > 0) {
            info("Successfully synced {$synced} plans.");
        }

        if (!empty($errors)) {
            warning('Errors occurred:');
            foreach ($errors as $error) {
                $this->line("  - {$error['plan']}: {$error['error']}");
            }
        }

        return empty($errors) ? self::SUCCESS : self::FAILURE;
    }

    private function syncPlan(Plan $plan, string $driver): void
    {
        $gateway = Payment::driver($driver);

        if ($driver === 'stripe') {
            $this->syncStripe($plan);
        } elseif ($driver === 'paypal') {
            $this->syncPayPal($plan);
        } else {
            throw new InvalidArgumentException("Unsupported driver for sync: {$driver}");
        }
    }

    private function syncStripe(Plan $plan): void
    {
        $stripe = Payment::driver('stripe');

        // Create or update product
        if (empty($plan->stripe_product_id)) {
            // Create new product
            $product = $stripe->getStripeClient()->products->create([
                'name' => $plan->name,
                'description' => $plan->description,
                'metadata' => [
                    'plan_id' => $plan->id,
                    'slug' => $plan->slug,
                ],
            ]);

            $plan->stripe_product_id = $product->id;
        }

        // Create or update price
        if (empty($plan->stripe_price_id)) {
            $price = $stripe->getStripeClient()->prices->create([
                'product' => $plan->stripe_product_id,
                'unit_amount' => $plan->amount,
                'currency' => mb_strtolower($plan->currency),
                'recurring' => [
                    'interval' => $plan->interval,
                    'interval_count' => $plan->interval_count,
                ],
                'metadata' => [
                    'plan_id' => $plan->id,
                    'slug' => $plan->slug,
                ],
            ]);

            $plan->stripe_price_id = $price->id;
        }

        $plan->save();

        $this->components->twoColumnDetail($plan->name, "Stripe: {$plan->stripe_price_id}");
    }

    private function syncPayPal(Plan $plan): void
    {
        // PayPal plan sync would go here
        // For now, just mark as needing manual setup
        warning("PayPal sync not yet implemented. Set paypal_plan_id manually for: {$plan->name}");
    }
}

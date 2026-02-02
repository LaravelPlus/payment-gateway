<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\note;
use function Laravel\Prompts\warning;

final class InstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'payment-gateway:install
                            {--force : Overwrite existing files}
                            {--skip-migrations : Skip running migrations}
                            {--skip-seed : Skip seeding default plans}';

    /**
     * The console command description.
     */
    protected $description = 'Install the Payment Gateway package';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->printBanner();

        // Step 1: Select what to install
        $options = multiselect(
            label: 'What would you like to install?',
            options: [
                'config' => 'Configuration file',
                'migrations' => 'Database migrations',
                'billable' => 'Add Billable trait to User model',
                'plans' => 'Seed default subscription plans',
                'env' => 'Add environment variables to .env.example',
            ],
            default: ['config', 'migrations', 'billable', 'env'],
            required: true,
        );

        // Step 2: Publish config
        if (in_array('config', $options)) {
            $this->publishConfig();
        }

        // Step 3: Publish and run migrations
        if (in_array('migrations', $options)) {
            $this->publishMigrations();

            if (!$this->option('skip-migrations')) {
                $this->runMigrations();
            }
        }

        // Step 4: Add Billable trait to User model
        if (in_array('billable', $options)) {
            $this->addBillableTrait();
        }

        // Step 5: Seed default plans
        if (in_array('plans', $options) && !$this->option('skip-seed')) {
            $this->seedPlans();
        }

        // Step 6: Add env variables
        if (in_array('env', $options)) {
            $this->addEnvVariables();
        }

        // Step 7: Show next steps
        $this->showNextSteps();

        return self::SUCCESS;
    }

    private function printBanner(): void
    {
        $this->newLine();
        info('╔════════════════════════════════════════════╗');
        info('║     Payment Gateway - Installation         ║');
        info('╚════════════════════════════════════════════╝');
        $this->newLine();
    }

    private function publishConfig(): void
    {
        info('Publishing configuration...');

        $params = ['--tag' => 'payment-gateway-config'];

        if ($this->option('force')) {
            $params['--force'] = true;
        }

        $this->call('vendor:publish', $params);

        $this->components->twoColumnDetail('Config file', 'config/payment-gateway.php');
    }

    private function publishMigrations(): void
    {
        info('Publishing migrations...');

        $params = ['--tag' => 'payment-gateway-migrations'];

        if ($this->option('force')) {
            $params['--force'] = true;
        }

        $this->call('vendor:publish', $params);

        $this->components->twoColumnDetail('Migrations', 'database/migrations/');
    }

    private function runMigrations(): void
    {
        if (confirm('Run migrations now?', true)) {
            info('Running migrations...');
            $this->call('migrate');
        } else {
            warning('Skipped migrations. Run `php artisan migrate` when ready.');
        }
    }

    private function addBillableTrait(): void
    {
        $userModelPath = app_path('Models/User.php');

        if (!File::exists($userModelPath)) {
            warning('User model not found at app/Models/User.php');

            return;
        }

        $content = File::get($userModelPath);

        // Check if already added
        if (str_contains($content, 'use Nejcc\PaymentGateway\Traits\Billable')) {
            note('Billable trait already added to User model.');

            return;
        }

        info('Adding Billable trait to User model...');

        // Add import
        $importStatement = "use Nejcc\\PaymentGateway\\Traits\\Billable;\n";

        // Find the last use statement in imports
        if (preg_match('/^(use [^;]+;\n)(?!use )/m', $content, $matches, PREG_OFFSET_SET)) {
            // Find all use statements and get position after the last one
            preg_match_all('/^use [^;]+;\n/m', $content, $allMatches, PREG_OFFSET_SET);
            $lastMatch = end($allMatches[0]);
            $insertPosition = $lastMatch[1] + mb_strlen($lastMatch[0]);

            $content = mb_substr($content, 0, $insertPosition).$importStatement.mb_substr($content, $insertPosition);
        }

        // Add trait to class
        if (preg_match('/use\s+(HasFactory|Notifiable|HasRoles)[^;]*;/', $content, $matches)) {
            $content = preg_replace(
                '/(use\s+)(HasFactory|Notifiable|HasRoles)/',
                '$1Billable, $2',
                $content,
                1
            );
        }

        File::put($userModelPath, $content);

        $this->components->twoColumnDetail('User model', 'Billable trait added');
    }

    private function seedPlans(): void
    {
        if (!confirm('Seed default subscription plans?', true)) {
            return;
        }

        info('Seeding default plans...');

        // Copy seeder to app
        $seederSource = __DIR__.'/../../../database/seeders/PlanSeeder.php';
        $seederDest = database_path('seeders/PlanSeeder.php');

        if (File::exists($seederSource)) {
            $content = File::get($seederSource);
            // Update namespace
            $content = str_replace(
                'namespace Nejcc\PaymentGateway\Database\Seeders;',
                'namespace Database\Seeders;',
                $content
            );
            File::put($seederDest, $content);

            $this->call('db:seed', ['--class' => 'PlanSeeder']);

            $this->components->twoColumnDetail('Plans seeded', '6 default plans created');
        } else {
            warning('PlanSeeder not found in package.');
        }
    }

    private function addEnvVariables(): void
    {
        $envExamplePath = base_path('.env.example');
        $envPath = base_path('.env');

        $envContent = <<<'ENV'

# Payment Gateway
PAYMENT_DRIVER=stripe
PAYMENT_CURRENCY=EUR

# Stripe
STRIPE_KEY=
STRIPE_SECRET=
STRIPE_WEBHOOK_SECRET=

# PayPal
PAYPAL_CLIENT_ID=
PAYPAL_CLIENT_SECRET=
PAYPAL_MODE=sandbox

# Subscriptions
SUBSCRIPTION_TRIAL_DAYS=14
SUBSCRIPTION_ALLOW_FREE=true
ENV;

        // Add to .env.example
        if (File::exists($envExamplePath)) {
            $existingContent = File::get($envExamplePath);

            if (!str_contains($existingContent, 'PAYMENT_DRIVER')) {
                File::append($envExamplePath, $envContent);
                $this->components->twoColumnDetail('.env.example', 'Payment variables added');
            } else {
                note('Payment variables already in .env.example');
            }
        }

        // Optionally add to .env
        if (File::exists($envPath) && confirm('Add payment variables to .env?', false)) {
            $existingContent = File::get($envPath);

            if (!str_contains($existingContent, 'PAYMENT_DRIVER')) {
                File::append($envPath, $envContent);
                $this->components->twoColumnDetail('.env', 'Payment variables added');
            }
        }
    }

    private function showNextSteps(): void
    {
        $this->newLine();
        info('╔════════════════════════════════════════════╗');
        info('║          Installation Complete!            ║');
        info('╚════════════════════════════════════════════╝');
        $this->newLine();

        note('Next steps:');
        $this->newLine();

        $this->line('  1. Configure your payment credentials in <comment>.env</comment>:');
        $this->line('     STRIPE_KEY=pk_test_xxx');
        $this->line('     STRIPE_SECRET=sk_test_xxx');
        $this->newLine();

        $this->line('  2. Sync plans with Stripe (optional):');
        $this->line('     <comment>php artisan payment-gateway:sync-plans</comment>');
        $this->newLine();

        $this->line('  3. Start using the package:');
        $this->line('     <comment>Payment::driver(\'stripe\')->createPaymentIntent(1999, \'USD\');</comment>');
        $this->newLine();

        $this->line('  Documentation: <href=https://github.com/nejcc/payment-gateway>https://github.com/nejcc/payment-gateway</>');
        $this->newLine();
    }
}

<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Database\Seeders;

use Illuminate\Database\Seeder;
use Nejcc\PaymentGateway\Models\Plan;

final class PlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $currency = config('payment-gateway.currency', 'EUR');

        $plans = [
            // Free Plan
            [
                'slug' => 'free',
                'name' => 'Free',
                'description' => 'Perfect for getting started and exploring the platform.',
                'amount' => 0,
                'currency' => $currency,
                'interval' => 'month',
                'interval_count' => 1,
                'trial_days' => null,
                'features' => [
                    '1 Project',
                    '100 MB Storage',
                    'Community Support',
                    'Basic Analytics',
                ],
                'limits' => [
                    'projects' => 1,
                    'storage_mb' => 100,
                    'api_calls' => 1000,
                ],
                'is_active' => true,
                'is_public' => true,
                'is_featured' => false,
                'sort_order' => 1,
            ],

            // Hobby Plan
            [
                'slug' => 'hobby',
                'name' => 'Hobby',
                'description' => 'Great for personal projects and side hustles.',
                'amount' => 900, // 9.00
                'currency' => $currency,
                'interval' => 'month',
                'interval_count' => 1,
                'trial_days' => 14,
                'features' => [
                    '5 Projects',
                    '5 GB Storage',
                    'Email Support',
                    'Advanced Analytics',
                    'Custom Domain',
                ],
                'limits' => [
                    'projects' => 5,
                    'storage_mb' => 5120,
                    'api_calls' => 10000,
                ],
                'is_active' => true,
                'is_public' => true,
                'is_featured' => false,
                'sort_order' => 2,
            ],

            // Business Plan
            [
                'slug' => 'business',
                'name' => 'Business',
                'description' => 'For growing businesses that need more power.',
                'amount' => 2900, // 29.00
                'currency' => $currency,
                'interval' => 'month',
                'interval_count' => 1,
                'trial_days' => 14,
                'features' => [
                    'Unlimited Projects',
                    '50 GB Storage',
                    'Priority Support',
                    'Advanced Analytics',
                    'Custom Domain',
                    'Team Collaboration',
                    'API Access',
                    'Webhooks',
                ],
                'limits' => [
                    'projects' => -1, // unlimited
                    'storage_mb' => 51200,
                    'api_calls' => 100000,
                    'team_members' => 10,
                ],
                'is_active' => true,
                'is_public' => true,
                'is_featured' => true,
                'sort_order' => 3,
            ],

            // Enterprise Plan
            [
                'slug' => 'enterprise',
                'name' => 'Enterprise',
                'description' => 'For large organizations with advanced needs.',
                'amount' => 9900, // 99.00
                'currency' => $currency,
                'interval' => 'month',
                'interval_count' => 1,
                'trial_days' => 30,
                'features' => [
                    'Unlimited Projects',
                    'Unlimited Storage',
                    'Dedicated Support',
                    'Advanced Analytics',
                    'Custom Domain',
                    'Team Collaboration',
                    'API Access',
                    'Webhooks',
                    'SSO/SAML',
                    'Audit Logs',
                    'SLA Guarantee',
                    'Custom Integrations',
                ],
                'limits' => [
                    'projects' => -1,
                    'storage_mb' => -1,
                    'api_calls' => -1,
                    'team_members' => -1,
                ],
                'is_active' => true,
                'is_public' => true,
                'is_featured' => false,
                'sort_order' => 4,
            ],
        ];

        foreach ($plans as $planData) {
            Plan::updateOrCreate(
                ['slug' => $planData['slug']],
                $planData
            );
        }
    }
}

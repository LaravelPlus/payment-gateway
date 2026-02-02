<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nejcc\PaymentGateway\Models\Plan;

/**
 * @extends Factory<Plan>
 */
final class PlanFactory extends Factory
{
    protected $model = Plan::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->randomElement(['Basic', 'Pro', 'Premium', 'Enterprise']);
        $amount = $this->faker->randomElement([999, 1999, 2999, 4999, 9999]);

        return [
            'name' => $name,
            'slug' => mb_strtolower($name),
            'description' => $this->faker->sentence(),
            'amount' => $amount,
            'currency' => 'USD',
            'interval' => 'month',
            'interval_count' => 1,
            'trial_days' => 0,
            'sort_order' => $this->faker->numberBetween(1, 10),
            'is_active' => true,
            'is_public' => true,
            'features' => [
                ['name' => 'Feature 1', 'included' => true],
                ['name' => 'Feature 2', 'included' => true],
                ['name' => 'Feature 3', 'included' => false],
            ],
            'limits' => [
                'projects' => 10,
                'users' => 5,
                'storage' => 10240, // 10 GB in MB
            ],
            'metadata' => null,
        ];
    }

    /**
     * Create a free plan.
     */
    public function free(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Free',
            'slug' => 'free',
            'amount' => 0,
            'trial_days' => 0,
            'limits' => [
                'projects' => 1,
                'users' => 1,
                'storage' => 1024, // 1 GB
            ],
        ]);
    }

    /**
     * Create a monthly plan.
     */
    public function monthly(): static
    {
        return $this->state(fn (array $attributes) => [
            'interval' => 'month',
            'interval_count' => 1,
        ]);
    }

    /**
     * Create a yearly plan.
     */
    public function yearly(): static
    {
        return $this->state(fn (array $attributes) => [
            'interval' => 'year',
            'interval_count' => 1,
            'amount' => ($attributes['amount'] ?? 1999) * 10, // ~17% discount
        ]);
    }

    /**
     * Add a trial period.
     */
    public function withTrial(int $days = 14): static
    {
        return $this->state(fn (array $attributes) => [
            'trial_days' => $days,
        ]);
    }

    /**
     * Make the plan inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Make the plan private.
     */
    public function private(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_public' => false,
        ]);
    }

    /**
     * Set a specific amount in cents.
     */
    public function amount(int $cents): static
    {
        return $this->state(fn (array $attributes) => [
            'amount' => $cents,
        ]);
    }
}

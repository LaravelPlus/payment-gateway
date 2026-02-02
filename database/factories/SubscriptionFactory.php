<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nejcc\PaymentGateway\Enums\SubscriptionStatus;
use Nejcc\PaymentGateway\Models\Subscription;

/**
 * @extends Factory<Subscription>
 */
final class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startDate = $this->faker->dateTimeBetween('-1 month', 'now');
        $endDate = (clone $startDate)->modify('+1 month');

        return [
            'user_id' => null,
            'payment_customer_id' => null,
            'plan_id' => null,
            'driver' => $this->faker->randomElement(['stripe', 'paypal']),
            'provider_id' => 'sub_'.$this->faker->unique()->regexify('[A-Za-z0-9]{24}'),
            'provider_plan_id' => 'price_'.$this->faker->regexify('[A-Za-z0-9]{24}'),
            'status' => SubscriptionStatus::Active->value,
            'amount' => $this->faker->numberBetween(999, 9999),
            'currency' => 'USD',
            'interval' => 'month',
            'interval_count' => 1,
            'quantity' => 1,
            'current_period_start' => $startDate,
            'current_period_end' => $endDate,
            'trial_start' => null,
            'trial_end' => null,
            'canceled_at' => null,
            'ended_at' => null,
            'cancel_at_period_end' => false,
            'metadata' => null,
            'provider_response' => [],
        ];
    }

    /**
     * Indicate that the subscription is active.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SubscriptionStatus::Active->value,
        ]);
    }

    /**
     * Indicate that the subscription is trialing.
     */
    public function trialing(): static
    {
        $trialEnd = now()->addDays(14);

        return $this->state(fn (array $attributes) => [
            'status' => SubscriptionStatus::Trialing->value,
            'trial_start' => now(),
            'trial_end' => $trialEnd,
        ]);
    }

    /**
     * Indicate that the subscription is canceled.
     */
    public function canceled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SubscriptionStatus::Canceled->value,
            'canceled_at' => now(),
            'ended_at' => now(),
        ]);
    }

    /**
     * Indicate that the subscription is past due.
     */
    public function pastDue(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SubscriptionStatus::PastDue->value,
        ]);
    }

    /**
     * Indicate that the subscription will cancel at period end.
     */
    public function cancelingAtPeriodEnd(): static
    {
        return $this->state(fn (array $attributes) => [
            'cancel_at_period_end' => true,
            'canceled_at' => now(),
        ]);
    }

    /**
     * Set the subscription to monthly billing.
     */
    public function monthly(): static
    {
        return $this->state(fn (array $attributes) => [
            'interval' => 'month',
            'interval_count' => 1,
        ]);
    }

    /**
     * Set the subscription to yearly billing.
     */
    public function yearly(): static
    {
        $startDate = now();
        $endDate = now()->addYear();

        return $this->state(fn (array $attributes) => [
            'interval' => 'year',
            'interval_count' => 1,
            'current_period_start' => $startDate,
            'current_period_end' => $endDate,
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

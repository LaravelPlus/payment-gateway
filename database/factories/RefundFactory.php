<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nejcc\PaymentGateway\Models\Refund;

/**
 * @extends Factory<Refund>
 */
final class RefundFactory extends Factory
{
    protected $model = Refund::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'transaction_id' => null,
            'driver' => $this->faker->randomElement(['stripe', 'paypal']),
            'provider_id' => 're_'.$this->faker->unique()->regexify('[A-Za-z0-9]{24}'),
            'amount' => $this->faker->numberBetween(100, 10000),
            'currency' => 'USD',
            'status' => 'succeeded',
            'reason' => $this->faker->optional()->randomElement([
                'duplicate',
                'fraudulent',
                'requested_by_customer',
            ]),
            'failure_reason' => null,
            'metadata' => null,
            'provider_response' => [],
        ];
    }

    /**
     * Indicate that the refund succeeded.
     */
    public function succeeded(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'succeeded',
        ]);
    }

    /**
     * Indicate that the refund is pending.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
        ]);
    }

    /**
     * Indicate that the refund failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'failure_reason' => $this->faker->sentence(),
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

    /**
     * Set the reason for the refund.
     */
    public function reason(string $reason): static
    {
        return $this->state(fn (array $attributes) => [
            'reason' => $reason,
        ]);
    }
}

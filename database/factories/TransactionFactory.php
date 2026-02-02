<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nejcc\PaymentGateway\Enums\PaymentStatus;
use Nejcc\PaymentGateway\Models\Transaction;

/**
 * @extends Factory<Transaction>
 */
final class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'payment_customer_id' => null,
            'subscription_id' => null,
            'driver' => $this->faker->randomElement(['stripe', 'paypal']),
            'provider_id' => 'txn_'.$this->faker->unique()->regexify('[A-Za-z0-9]{24}'),
            'amount' => $this->faker->numberBetween(1000, 100000), // 10.00 to 1000.00
            'currency' => 'USD',
            'status' => PaymentStatus::Succeeded->value,
            'payment_method' => 'pm_'.$this->faker->regexify('[A-Za-z0-9]{24}'),
            'description' => $this->faker->sentence(),
            'metadata' => null,
            'provider_response' => [],
        ];
    }

    /**
     * Indicate that the transaction is pending.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentStatus::Pending->value,
        ]);
    }

    /**
     * Indicate that the transaction succeeded.
     */
    public function succeeded(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentStatus::Succeeded->value,
        ]);
    }

    /**
     * Indicate that the transaction failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentStatus::Failed->value,
            'failure_reason' => $this->faker->sentence(),
        ]);
    }

    /**
     * Indicate that the transaction was refunded.
     */
    public function refunded(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentStatus::Refunded->value,
        ]);
    }

    /**
     * Set the driver to Stripe.
     */
    public function stripe(): static
    {
        return $this->state(fn (array $attributes) => [
            'driver' => 'stripe',
            'provider_id' => 'pi_'.$this->faker->unique()->regexify('[A-Za-z0-9]{24}'),
        ]);
    }

    /**
     * Set the driver to PayPal.
     */
    public function paypal(): static
    {
        return $this->state(fn (array $attributes) => [
            'driver' => 'paypal',
            'provider_id' => $this->faker->unique()->regexify('[A-Z0-9]{17}'),
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

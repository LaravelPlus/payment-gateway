<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nejcc\PaymentGateway\Models\PaymentCustomer;

/**
 * @extends Factory<PaymentCustomer>
 */
final class PaymentCustomerFactory extends Factory
{
    protected $model = PaymentCustomer::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'driver' => $this->faker->randomElement(['stripe', 'paypal']),
            'provider_id' => 'cus_'.$this->faker->unique()->regexify('[A-Za-z0-9]{24}'),
            'email' => $this->faker->email(),
            'name' => $this->faker->name(),
            'metadata' => null,
            'provider_response' => [],
        ];
    }

    /**
     * Set the driver to Stripe.
     */
    public function stripe(): static
    {
        return $this->state(fn (array $attributes) => [
            'driver' => 'stripe',
            'provider_id' => 'cus_'.$this->faker->unique()->regexify('[A-Za-z0-9]{24}'),
        ]);
    }

    /**
     * Set the driver to PayPal.
     */
    public function paypal(): static
    {
        return $this->state(fn (array $attributes) => [
            'driver' => 'paypal',
            'provider_id' => $this->faker->unique()->regexify('[A-Z0-9]{13}'),
        ]);
    }
}

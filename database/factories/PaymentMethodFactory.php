<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nejcc\PaymentGateway\Models\PaymentMethod;

/**
 * @extends Factory<PaymentMethod>
 */
final class PaymentMethodFactory extends Factory
{
    protected $model = PaymentMethod::class;

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
            'driver' => 'stripe',
            'provider_id' => 'pm_'.$this->faker->unique()->regexify('[A-Za-z0-9]{24}'),
            'type' => 'card',
            'brand' => $this->faker->randomElement(['visa', 'mastercard', 'amex', 'discover']),
            'last_four' => $this->faker->numerify('####'),
            'exp_month' => $this->faker->numberBetween(1, 12),
            'exp_year' => $this->faker->numberBetween(2025, 2030),
            'is_default' => false,
            'billing_address' => [
                'name' => $this->faker->name(),
                'line1' => $this->faker->streetAddress(),
                'city' => $this->faker->city(),
                'state' => $this->faker->stateAbbr(),
                'postal_code' => $this->faker->postcode(),
                'country' => 'US',
            ],
            'metadata' => null,
            'provider_response' => [],
        ];
    }

    /**
     * Indicate that this is the default payment method.
     */
    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
        ]);
    }

    /**
     * Create a Visa card.
     */
    public function visa(): static
    {
        return $this->state(fn (array $attributes) => [
            'brand' => 'visa',
            'last_four' => '4242',
        ]);
    }

    /**
     * Create a Mastercard.
     */
    public function mastercard(): static
    {
        return $this->state(fn (array $attributes) => [
            'brand' => 'mastercard',
            'last_four' => '5556',
        ]);
    }

    /**
     * Create an American Express card.
     */
    public function amex(): static
    {
        return $this->state(fn (array $attributes) => [
            'brand' => 'amex',
            'last_four' => '8431',
        ]);
    }

    /**
     * Create an expired card.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'exp_month' => 12,
            'exp_year' => 2020,
        ]);
    }
}

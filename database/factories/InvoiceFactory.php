<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nejcc\PaymentGateway\Enums\InvoiceStatus;
use Nejcc\PaymentGateway\Models\Invoice;

/**
 * @extends Factory<Invoice>
 */
final class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = $this->faker->numberBetween(1000, 100000);
        $tax = (int) round($subtotal * 0.1); // 10% tax
        $total = $subtotal + $tax;

        return [
            'user_id' => null,
            'payment_customer_id' => null,
            'subscription_id' => null,
            'transaction_id' => null,
            'number' => Invoice::generateInvoiceNumber(),
            'driver' => $this->faker->randomElement(['stripe', 'paypal']),
            'provider_id' => 'in_'.$this->faker->unique()->regexify('[A-Za-z0-9]{24}'),
            'status' => InvoiceStatus::Paid->value,
            'subtotal' => $subtotal,
            'tax' => $tax,
            'discount' => 0,
            'total' => $total,
            'amount_paid' => $total,
            'amount_due' => 0,
            'currency' => 'USD',
            'tax_rate' => 10.00,
            'billing_name' => $this->faker->name(),
            'billing_email' => $this->faker->email(),
            'billing_address' => $this->faker->streetAddress(),
            'billing_city' => $this->faker->city(),
            'billing_state' => $this->faker->stateAbbr(),
            'billing_postal_code' => $this->faker->postcode(),
            'billing_country' => $this->faker->countryCode(),
            'billing_company' => $this->faker->optional()->company(),
            'invoice_date' => now(),
            'due_date' => now()->addDays(30),
            'paid_at' => now(),
            'line_items' => [
                [
                    'description' => $this->faker->sentence(3),
                    'quantity' => 1,
                    'unit_price' => $subtotal,
                    'amount' => $subtotal,
                ],
            ],
            'notes' => null,
            'footer' => null,
            'metadata' => null,
            'provider_response' => [],
        ];
    }

    /**
     * Create a draft invoice.
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => InvoiceStatus::Draft->value,
            'amount_paid' => 0,
            'amount_due' => $attributes['total'],
            'paid_at' => null,
        ]);
    }

    /**
     * Create an open invoice.
     */
    public function open(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => InvoiceStatus::Open->value,
            'amount_paid' => 0,
            'amount_due' => $attributes['total'],
            'paid_at' => null,
        ]);
    }

    /**
     * Create a paid invoice.
     */
    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => InvoiceStatus::Paid->value,
            'amount_paid' => $attributes['total'],
            'amount_due' => 0,
            'paid_at' => now(),
        ]);
    }

    /**
     * Create a voided invoice.
     */
    public function void(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => InvoiceStatus::Void->value,
            'voided_at' => now(),
        ]);
    }

    /**
     * Create an overdue invoice.
     */
    public function overdue(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => InvoiceStatus::Open->value,
            'amount_paid' => 0,
            'amount_due' => $attributes['total'],
            'due_date' => now()->subDays(7),
            'paid_at' => null,
        ]);
    }

    /**
     * Add multiple line items.
     */
    public function withLineItems(int $count = 3): static
    {
        return $this->state(function (array $attributes) use ($count) {
            $lineItems = [];
            $subtotal = 0;

            for ($i = 0; $i < $count; $i++) {
                $quantity = $this->faker->numberBetween(1, 5);
                $unitPrice = $this->faker->numberBetween(500, 5000);
                $amount = $quantity * $unitPrice;
                $subtotal += $amount;

                $lineItems[] = [
                    'description' => $this->faker->sentence(3),
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'amount' => $amount,
                ];
            }

            $tax = (int) round($subtotal * 0.1);
            $total = $subtotal + $tax;

            return [
                'line_items' => $lineItems,
                'subtotal' => $subtotal,
                'tax' => $tax,
                'total' => $total,
                'amount_paid' => $total,
                'amount_due' => 0,
            ];
        });
    }
}

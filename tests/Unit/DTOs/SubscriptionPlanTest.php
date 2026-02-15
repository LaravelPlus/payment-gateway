<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Tests\Unit\DTOs;

use LaravelPlus\PaymentGateway\DTOs\SubscriptionPlan;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SubscriptionPlanTest extends TestCase
{
    #[Test]
    public function it_creates_from_array_and_round_trips(): void
    {
        $data = [
            'id' => 'price_123',
            'product_id' => 'prod_456',
            'name' => 'Pro Plan',
            'amount' => 2999,
            'currency' => 'usd',
            'interval' => 'month',
            'interval_count' => 1,
            'driver' => 'stripe',
            'description' => 'Professional monthly plan',
            'trial_days' => 14,
            'features' => ['Unlimited projects', 'Priority support'],
            'is_active' => true,
            'metadata' => ['tier' => 'pro'],
        ];

        $plan = SubscriptionPlan::fromArray($data);

        $this->assertSame('price_123', $plan->id);
        $this->assertSame('prod_456', $plan->productId);
        $this->assertSame('Pro Plan', $plan->name);
        $this->assertSame(2999, $plan->amount);
        $this->assertSame('USD', $plan->currency);
        $this->assertSame('month', $plan->interval);
        $this->assertSame(1, $plan->intervalCount);
        $this->assertSame('stripe', $plan->driver);
        $this->assertSame('Professional monthly plan', $plan->description);
        $this->assertSame(14, $plan->trialDays);
        $this->assertSame(['Unlimited projects', 'Priority support'], $plan->features);
        $this->assertTrue($plan->isActive);
        $this->assertSame(['tier' => 'pro'], $plan->metadata);

        $array = $plan->toArray();
        $roundTripped = SubscriptionPlan::fromArray($array);

        $this->assertSame($plan->id, $roundTripped->id);
        $this->assertSame($plan->name, $roundTripped->name);
        $this->assertSame($plan->amount, $roundTripped->amount);
        $this->assertSame($plan->interval, $roundTripped->interval);
        $this->assertSame($plan->intervalCount, $roundTripped->intervalCount);
    }

    #[Test]
    public function to_array_includes_computed_fields(): void
    {
        $plan = new SubscriptionPlan(
            id: 'price_1',
            productId: 'prod_1',
            name: 'Basic',
            amount: 999,
            currency: 'USD',
            interval: 'month',
            intervalCount: 1,
            driver: 'stripe',
        );

        $array = $plan->toArray();

        $this->assertSame('price_1', $array['id']);
        $this->assertSame('prod_1', $array['product_id']);
        $this->assertSame('Basic', $array['name']);
        $this->assertSame(999, $array['amount']);
        $this->assertSame(9.99, $array['amount_decimal']);
        $this->assertSame('USD', $array['currency']);
        $this->assertSame('month', $array['interval']);
        $this->assertSame(1, $array['interval_count']);
        $this->assertArrayHasKey('interval_label', $array);
        $this->assertArrayHasKey('billing_description', $array);
        $this->assertSame('stripe', $array['driver']);
        $this->assertNull($array['description']);
        $this->assertNull($array['trial_days']);
        $this->assertSame([], $array['features']);
        $this->assertTrue($array['is_active']);
        $this->assertSame([], $array['metadata']);
    }

    #[Test]
    public function get_amount_decimal_converts_cents_to_decimal(): void
    {
        $plan = new SubscriptionPlan(
            id: 'price_1',
            productId: 'prod_1',
            name: 'Plan',
            amount: 1050,
            currency: 'USD',
            interval: 'month',
            intervalCount: 1,
            driver: 'stripe',
        );

        $this->assertSame(10.5, $plan->getAmountDecimal());
    }

    #[Test]
    public function get_amount_decimal_handles_zero(): void
    {
        $plan = new SubscriptionPlan(
            id: 'price_1',
            productId: 'prod_1',
            name: 'Free',
            amount: 0,
            currency: 'USD',
            interval: 'month',
            intervalCount: 1,
            driver: 'stripe',
        );

        $this->assertSame(0.0, $plan->getAmountDecimal());
    }

    #[Test]
    public function get_formatted_amount_returns_currency_string(): void
    {
        $plan = new SubscriptionPlan(
            id: 'price_1',
            productId: 'prod_1',
            name: 'Plan',
            amount: 2999,
            currency: 'USD',
            interval: 'month',
            intervalCount: 1,
            driver: 'stripe',
        );

        $formatted = $plan->getFormattedAmount();

        $this->assertStringContainsString('29', $formatted);
        $this->assertStringContainsString('99', $formatted);
    }

    #[Test]
    public function get_billing_description_for_single_interval(): void
    {
        $plan = new SubscriptionPlan(
            id: 'price_1',
            productId: 'prod_1',
            name: 'Plan',
            amount: 999,
            currency: 'USD',
            interval: 'month',
            intervalCount: 1,
            driver: 'stripe',
        );

        $desc = $plan->getBillingDescription();

        $this->assertStringContainsString('/ month', $desc);
    }

    #[Test]
    public function get_billing_description_for_multiple_intervals(): void
    {
        $plan = new SubscriptionPlan(
            id: 'price_1',
            productId: 'prod_1',
            name: 'Plan',
            amount: 2999,
            currency: 'USD',
            interval: 'month',
            intervalCount: 3,
            driver: 'stripe',
        );

        $desc = $plan->getBillingDescription();

        $this->assertStringContainsString('/ 3 months', $desc);
    }

    #[Test]
    public function get_interval_label_daily(): void
    {
        $plan = new SubscriptionPlan(
            id: 'price_1',
            productId: 'prod_1',
            name: 'Plan',
            amount: 100,
            currency: 'USD',
            interval: 'day',
            intervalCount: 1,
            driver: 'stripe',
        );

        $this->assertSame('Daily', $plan->getIntervalLabel());
    }

    #[Test]
    public function get_interval_label_weekly(): void
    {
        $plan = new SubscriptionPlan(
            id: 'price_1',
            productId: 'prod_1',
            name: 'Plan',
            amount: 500,
            currency: 'USD',
            interval: 'week',
            intervalCount: 1,
            driver: 'stripe',
        );

        $this->assertSame('Weekly', $plan->getIntervalLabel());
    }

    #[Test]
    public function get_interval_label_monthly(): void
    {
        $plan = new SubscriptionPlan(
            id: 'price_1',
            productId: 'prod_1',
            name: 'Plan',
            amount: 999,
            currency: 'USD',
            interval: 'month',
            intervalCount: 1,
            driver: 'stripe',
        );

        $this->assertSame('Monthly', $plan->getIntervalLabel());
    }

    #[Test]
    public function get_interval_label_yearly(): void
    {
        $plan = new SubscriptionPlan(
            id: 'price_1',
            productId: 'prod_1',
            name: 'Plan',
            amount: 9999,
            currency: 'USD',
            interval: 'year',
            intervalCount: 1,
            driver: 'stripe',
        );

        $this->assertSame('Yearly', $plan->getIntervalLabel());
    }

    #[Test]
    public function get_interval_label_custom_multiple_intervals(): void
    {
        $plan = new SubscriptionPlan(
            id: 'price_1',
            productId: 'prod_1',
            name: 'Plan',
            amount: 2999,
            currency: 'USD',
            interval: 'month',
            intervalCount: 3,
            driver: 'stripe',
        );

        $this->assertSame('Every 3 months', $plan->getIntervalLabel());
    }

    #[Test]
    public function get_interval_label_custom_unknown_interval(): void
    {
        $plan = new SubscriptionPlan(
            id: 'price_1',
            productId: 'prod_1',
            name: 'Plan',
            amount: 100,
            currency: 'USD',
            interval: 'hour',
            intervalCount: 1,
            driver: 'stripe',
        );

        $this->assertSame('Hour', $plan->getIntervalLabel());
    }

    #[Test]
    public function from_array_defaults_interval_count_to_one(): void
    {
        $plan = SubscriptionPlan::fromArray([
            'id' => 'price_1',
            'product_id' => 'prod_1',
            'name' => 'Plan',
            'amount' => 999,
            'currency' => 'usd',
            'interval' => 'month',
            'driver' => 'stripe',
        ]);

        $this->assertSame(1, $plan->intervalCount);
    }

    #[Test]
    public function from_array_defaults_is_active_to_true(): void
    {
        $plan = SubscriptionPlan::fromArray([
            'id' => 'price_1',
            'product_id' => 'prod_1',
            'name' => 'Plan',
            'amount' => 999,
            'currency' => 'usd',
            'interval' => 'month',
            'driver' => 'stripe',
        ]);

        $this->assertTrue($plan->isActive);
    }

    #[Test]
    public function currency_is_uppercased(): void
    {
        $plan = SubscriptionPlan::fromArray([
            'id' => 'price_1',
            'product_id' => 'prod_1',
            'name' => 'Plan',
            'amount' => 999,
            'currency' => 'eur',
            'interval' => 'month',
            'driver' => 'stripe',
        ]);

        $this->assertSame('EUR', $plan->currency);
    }
}

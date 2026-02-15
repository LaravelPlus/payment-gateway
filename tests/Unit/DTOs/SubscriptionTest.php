<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Tests\Unit\DTOs;

use DateTimeImmutable;
use LaravelPlus\PaymentGateway\DTOs\Subscription;
use LaravelPlus\PaymentGateway\Enums\SubscriptionStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SubscriptionTest extends TestCase
{
    #[Test]
    public function it_creates_from_array_and_round_trips(): void
    {
        $data = [
            'id' => 'sub_123',
            'customer_id' => 'cus_456',
            'plan_id' => 'plan_789',
            'status' => 'active',
            'amount' => 1999,
            'currency' => 'usd',
            'interval' => 'month',
            'driver' => 'stripe',
            'current_period_start' => '2025-01-01T00:00:00+00:00',
            'current_period_end' => '2099-02-01T00:00:00+00:00',
            'trial_start' => '2024-12-01T00:00:00+00:00',
            'trial_end' => '2025-01-01T00:00:00+00:00',
            'cancel_at_period_end' => false,
            'metadata' => ['tier' => 'premium'],
        ];

        $sub = Subscription::fromArray($data);

        $this->assertSame('sub_123', $sub->id);
        $this->assertSame('cus_456', $sub->customerId);
        $this->assertSame('plan_789', $sub->planId);
        $this->assertSame(SubscriptionStatus::Active, $sub->status);
        $this->assertSame(1999, $sub->amount);
        $this->assertSame('USD', $sub->currency);
        $this->assertSame('month', $sub->interval);
        $this->assertSame('stripe', $sub->driver);
        $this->assertNotNull($sub->currentPeriodStart);
        $this->assertNotNull($sub->currentPeriodEnd);
        $this->assertNotNull($sub->trialStart);
        $this->assertNotNull($sub->trialEnd);
        $this->assertFalse($sub->cancelAtPeriodEnd);
        $this->assertSame(['tier' => 'premium'], $sub->metadata);

        $array = $sub->toArray();
        $roundTripped = Subscription::fromArray($array);

        $this->assertSame($sub->id, $roundTripped->id);
        $this->assertSame($sub->customerId, $roundTripped->customerId);
        $this->assertSame($sub->status, $roundTripped->status);
        $this->assertSame($sub->amount, $roundTripped->amount);
    }

    #[Test]
    public function to_array_includes_all_fields(): void
    {
        $sub = new Subscription(
            id: 'sub_1',
            customerId: 'cus_1',
            planId: 'plan_1',
            status: SubscriptionStatus::Active,
            amount: 2500,
            currency: 'EUR',
            interval: 'year',
            driver: 'stripe',
        );

        $array = $sub->toArray();

        $this->assertSame('sub_1', $array['id']);
        $this->assertSame('cus_1', $array['customer_id']);
        $this->assertSame('plan_1', $array['plan_id']);
        $this->assertSame('active', $array['status']);
        $this->assertSame(2500, $array['amount']);
        $this->assertSame(25.0, $array['amount_decimal']);
        $this->assertSame('EUR', $array['currency']);
        $this->assertSame('year', $array['interval']);
        $this->assertSame('stripe', $array['driver']);
        $this->assertNull($array['current_period_start']);
        $this->assertNull($array['current_period_end']);
        $this->assertNull($array['trial_start']);
        $this->assertNull($array['trial_end']);
        $this->assertNull($array['canceled_at']);
        $this->assertNull($array['ended_at']);
        $this->assertFalse($array['cancel_at_period_end']);
        $this->assertSame([], $array['metadata']);
    }

    #[Test]
    public function is_active_delegates_to_status_enum_for_active(): void
    {
        $sub = new Subscription(
            id: 'sub_1',
            customerId: 'cus_1',
            planId: 'plan_1',
            status: SubscriptionStatus::Active,
            amount: 1000,
            currency: 'USD',
            interval: 'month',
            driver: 'stripe',
        );

        $this->assertTrue($sub->isActive());
    }

    #[Test]
    public function is_active_delegates_to_status_enum_for_trialing(): void
    {
        $sub = new Subscription(
            id: 'sub_1',
            customerId: 'cus_1',
            planId: 'plan_1',
            status: SubscriptionStatus::Trialing,
            amount: 1000,
            currency: 'USD',
            interval: 'month',
            driver: 'stripe',
        );

        $this->assertTrue($sub->isActive());
    }

    #[Test]
    public function is_active_returns_false_for_canceled(): void
    {
        $sub = new Subscription(
            id: 'sub_1',
            customerId: 'cus_1',
            planId: 'plan_1',
            status: SubscriptionStatus::Canceled,
            amount: 1000,
            currency: 'USD',
            interval: 'month',
            driver: 'stripe',
        );

        $this->assertFalse($sub->isActive());
    }

    #[Test]
    public function on_trial_returns_true_when_trialing_with_future_trial_end(): void
    {
        $sub = new Subscription(
            id: 'sub_1',
            customerId: 'cus_1',
            planId: 'plan_1',
            status: SubscriptionStatus::Trialing,
            amount: 0,
            currency: 'USD',
            interval: 'month',
            driver: 'stripe',
            trialEnd: new DateTimeImmutable('2099-12-31T23:59:59+00:00'),
        );

        $this->assertTrue($sub->onTrial());
    }

    #[Test]
    public function on_trial_returns_false_when_trialing_with_past_trial_end(): void
    {
        $sub = new Subscription(
            id: 'sub_1',
            customerId: 'cus_1',
            planId: 'plan_1',
            status: SubscriptionStatus::Trialing,
            amount: 0,
            currency: 'USD',
            interval: 'month',
            driver: 'stripe',
            trialEnd: new DateTimeImmutable('2020-01-01T00:00:00+00:00'),
        );

        $this->assertFalse($sub->onTrial());
    }

    #[Test]
    public function on_trial_returns_false_when_not_trialing_status(): void
    {
        $sub = new Subscription(
            id: 'sub_1',
            customerId: 'cus_1',
            planId: 'plan_1',
            status: SubscriptionStatus::Active,
            amount: 1000,
            currency: 'USD',
            interval: 'month',
            driver: 'stripe',
            trialEnd: new DateTimeImmutable('2099-12-31T23:59:59+00:00'),
        );

        $this->assertFalse($sub->onTrial());
    }

    #[Test]
    public function on_trial_returns_false_when_trial_end_is_null(): void
    {
        $sub = new Subscription(
            id: 'sub_1',
            customerId: 'cus_1',
            planId: 'plan_1',
            status: SubscriptionStatus::Trialing,
            amount: 0,
            currency: 'USD',
            interval: 'month',
            driver: 'stripe',
        );

        $this->assertFalse($sub->onTrial());
    }

    #[Test]
    public function is_canceled_returns_true_when_canceled_at_is_set(): void
    {
        $sub = new Subscription(
            id: 'sub_1',
            customerId: 'cus_1',
            planId: 'plan_1',
            status: SubscriptionStatus::Active,
            amount: 1000,
            currency: 'USD',
            interval: 'month',
            driver: 'stripe',
            canceledAt: new DateTimeImmutable('2025-06-01T00:00:00+00:00'),
        );

        $this->assertTrue($sub->isCanceled());
    }

    #[Test]
    public function is_canceled_returns_true_when_status_is_canceled(): void
    {
        $sub = new Subscription(
            id: 'sub_1',
            customerId: 'cus_1',
            planId: 'plan_1',
            status: SubscriptionStatus::Canceled,
            amount: 1000,
            currency: 'USD',
            interval: 'month',
            driver: 'stripe',
        );

        $this->assertTrue($sub->isCanceled());
    }

    #[Test]
    public function is_canceled_returns_false_when_active_and_not_canceled(): void
    {
        $sub = new Subscription(
            id: 'sub_1',
            customerId: 'cus_1',
            planId: 'plan_1',
            status: SubscriptionStatus::Active,
            amount: 1000,
            currency: 'USD',
            interval: 'month',
            driver: 'stripe',
        );

        $this->assertFalse($sub->isCanceled());
    }

    #[Test]
    public function days_remaining_returns_positive_for_future_period_end(): void
    {
        $sub = new Subscription(
            id: 'sub_1',
            customerId: 'cus_1',
            planId: 'plan_1',
            status: SubscriptionStatus::Active,
            amount: 1000,
            currency: 'USD',
            interval: 'month',
            driver: 'stripe',
            currentPeriodEnd: new DateTimeImmutable('+30 days'),
        );

        $this->assertGreaterThan(0, $sub->daysRemaining());
    }

    #[Test]
    public function days_remaining_returns_zero_for_past_period_end(): void
    {
        $sub = new Subscription(
            id: 'sub_1',
            customerId: 'cus_1',
            planId: 'plan_1',
            status: SubscriptionStatus::Active,
            amount: 1000,
            currency: 'USD',
            interval: 'month',
            driver: 'stripe',
            currentPeriodEnd: new DateTimeImmutable('2020-01-01T00:00:00+00:00'),
        );

        $this->assertSame(0, $sub->daysRemaining());
    }

    #[Test]
    public function days_remaining_returns_zero_when_period_end_is_null(): void
    {
        $sub = new Subscription(
            id: 'sub_1',
            customerId: 'cus_1',
            planId: 'plan_1',
            status: SubscriptionStatus::Active,
            amount: 1000,
            currency: 'USD',
            interval: 'month',
            driver: 'stripe',
        );

        $this->assertSame(0, $sub->daysRemaining());
    }

    #[Test]
    public function get_amount_decimal_converts_cents(): void
    {
        $sub = new Subscription(
            id: 'sub_1',
            customerId: 'cus_1',
            planId: 'plan_1',
            status: SubscriptionStatus::Active,
            amount: 1999,
            currency: 'USD',
            interval: 'month',
            driver: 'stripe',
        );

        $this->assertSame(19.99, $sub->getAmountDecimal());
    }

    #[Test]
    public function status_is_parsed_from_string(): void
    {
        $sub = Subscription::fromArray([
            'id' => 'sub_1',
            'customer_id' => 'cus_1',
            'plan_id' => 'plan_1',
            'status' => 'past_due',
            'amount' => 100,
            'currency' => 'usd',
            'interval' => 'month',
            'driver' => 'stripe',
        ]);

        $this->assertSame(SubscriptionStatus::PastDue, $sub->status);
    }

    #[Test]
    public function status_accepts_enum_directly(): void
    {
        $sub = Subscription::fromArray([
            'id' => 'sub_1',
            'customer_id' => 'cus_1',
            'plan_id' => 'plan_1',
            'status' => SubscriptionStatus::Paused,
            'amount' => 100,
            'currency' => 'usd',
            'interval' => 'month',
            'driver' => 'stripe',
        ]);

        $this->assertSame(SubscriptionStatus::Paused, $sub->status);
    }

    #[Test]
    public function currency_is_uppercased(): void
    {
        $sub = Subscription::fromArray([
            'id' => 'sub_1',
            'customer_id' => 'cus_1',
            'plan_id' => 'plan_1',
            'status' => 'active',
            'amount' => 100,
            'currency' => 'gbp',
            'interval' => 'month',
            'driver' => 'stripe',
        ]);

        $this->assertSame('GBP', $sub->currency);
    }
}

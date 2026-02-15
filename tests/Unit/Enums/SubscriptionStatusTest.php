<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Tests\Unit\Enums;

use LaravelPlus\PaymentGateway\Enums\SubscriptionStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SubscriptionStatusTest extends TestCase
{
    #[Test]
    public function it_creates_active_from_value(): void
    {
        $this->assertSame(SubscriptionStatus::Active, SubscriptionStatus::from('active'));
    }

    #[Test]
    public function it_creates_trialing_from_value(): void
    {
        $this->assertSame(SubscriptionStatus::Trialing, SubscriptionStatus::from('trialing'));
    }

    #[Test]
    public function it_creates_past_due_from_value(): void
    {
        $this->assertSame(SubscriptionStatus::PastDue, SubscriptionStatus::from('past_due'));
    }

    #[Test]
    public function it_creates_paused_from_value(): void
    {
        $this->assertSame(SubscriptionStatus::Paused, SubscriptionStatus::from('paused'));
    }

    #[Test]
    public function it_creates_canceled_from_value(): void
    {
        $this->assertSame(SubscriptionStatus::Canceled, SubscriptionStatus::from('canceled'));
    }

    #[Test]
    public function it_creates_unpaid_from_value(): void
    {
        $this->assertSame(SubscriptionStatus::Unpaid, SubscriptionStatus::from('unpaid'));
    }

    #[Test]
    public function it_creates_incomplete_from_value(): void
    {
        $this->assertSame(SubscriptionStatus::Incomplete, SubscriptionStatus::from('incomplete'));
    }

    #[Test]
    public function it_creates_incomplete_expired_from_value(): void
    {
        $this->assertSame(SubscriptionStatus::IncompleteExpired, SubscriptionStatus::from('incomplete_expired'));
    }

    #[Test]
    public function it_returns_correct_label_for_active(): void
    {
        $this->assertSame('Active', SubscriptionStatus::Active->label());
    }

    #[Test]
    public function it_returns_correct_label_for_trialing(): void
    {
        $this->assertSame('Trial', SubscriptionStatus::Trialing->label());
    }

    #[Test]
    public function it_returns_correct_label_for_past_due(): void
    {
        $this->assertSame('Past Due', SubscriptionStatus::PastDue->label());
    }

    #[Test]
    public function it_returns_correct_label_for_paused(): void
    {
        $this->assertSame('Paused', SubscriptionStatus::Paused->label());
    }

    #[Test]
    public function it_returns_correct_label_for_canceled(): void
    {
        $this->assertSame('Canceled', SubscriptionStatus::Canceled->label());
    }

    #[Test]
    public function it_returns_correct_label_for_unpaid(): void
    {
        $this->assertSame('Unpaid', SubscriptionStatus::Unpaid->label());
    }

    #[Test]
    public function it_returns_correct_label_for_incomplete(): void
    {
        $this->assertSame('Incomplete', SubscriptionStatus::Incomplete->label());
    }

    #[Test]
    public function it_returns_correct_label_for_incomplete_expired(): void
    {
        $this->assertSame('Expired', SubscriptionStatus::IncompleteExpired->label());
    }

    #[Test]
    public function it_returns_green_color_for_active(): void
    {
        $this->assertSame('green', SubscriptionStatus::Active->color());
    }

    #[Test]
    public function it_returns_blue_color_for_trialing(): void
    {
        $this->assertSame('blue', SubscriptionStatus::Trialing->color());
    }

    #[Test]
    public function it_returns_yellow_color_for_past_due(): void
    {
        $this->assertSame('yellow', SubscriptionStatus::PastDue->color());
    }

    #[Test]
    public function it_returns_yellow_color_for_unpaid(): void
    {
        $this->assertSame('yellow', SubscriptionStatus::Unpaid->color());
    }

    #[Test]
    public function it_returns_gray_color_for_paused(): void
    {
        $this->assertSame('gray', SubscriptionStatus::Paused->color());
    }

    #[Test]
    public function it_returns_red_color_for_canceled(): void
    {
        $this->assertSame('red', SubscriptionStatus::Canceled->color());
    }

    #[Test]
    public function it_returns_red_color_for_incomplete_expired(): void
    {
        $this->assertSame('red', SubscriptionStatus::IncompleteExpired->color());
    }

    #[Test]
    public function it_returns_orange_color_for_incomplete(): void
    {
        $this->assertSame('orange', SubscriptionStatus::Incomplete->color());
    }

    #[Test]
    public function it_reports_active_as_active(): void
    {
        $this->assertTrue(SubscriptionStatus::Active->isActive());
    }

    #[Test]
    public function it_reports_trialing_as_active(): void
    {
        $this->assertTrue(SubscriptionStatus::Trialing->isActive());
    }

    #[Test]
    public function it_reports_paused_as_not_active(): void
    {
        $this->assertFalse(SubscriptionStatus::Paused->isActive());
    }

    #[Test]
    public function it_reports_canceled_as_not_active(): void
    {
        $this->assertFalse(SubscriptionStatus::Canceled->isActive());
    }

    #[Test]
    public function it_reports_past_due_as_not_active(): void
    {
        $this->assertFalse(SubscriptionStatus::PastDue->isActive());
    }

    #[Test]
    public function it_reports_incomplete_as_not_active(): void
    {
        $this->assertFalse(SubscriptionStatus::Incomplete->isActive());
    }

    #[Test]
    public function it_reports_past_due_as_grace_period(): void
    {
        $this->assertTrue(SubscriptionStatus::PastDue->isGracePeriod());
    }

    #[Test]
    public function it_reports_unpaid_as_grace_period(): void
    {
        $this->assertTrue(SubscriptionStatus::Unpaid->isGracePeriod());
    }

    #[Test]
    public function it_reports_active_as_not_grace_period(): void
    {
        $this->assertFalse(SubscriptionStatus::Active->isGracePeriod());
    }

    #[Test]
    public function it_reports_canceled_as_not_grace_period(): void
    {
        $this->assertFalse(SubscriptionStatus::Canceled->isGracePeriod());
    }

    #[Test]
    public function it_reports_paused_as_not_grace_period(): void
    {
        $this->assertFalse(SubscriptionStatus::Paused->isGracePeriod());
    }

    #[Test]
    public function it_reports_canceled_as_ended(): void
    {
        $this->assertTrue(SubscriptionStatus::Canceled->isEnded());
    }

    #[Test]
    public function it_reports_incomplete_expired_as_ended(): void
    {
        $this->assertTrue(SubscriptionStatus::IncompleteExpired->isEnded());
    }

    #[Test]
    public function it_reports_active_as_not_ended(): void
    {
        $this->assertFalse(SubscriptionStatus::Active->isEnded());
    }

    #[Test]
    public function it_reports_paused_as_not_ended(): void
    {
        $this->assertFalse(SubscriptionStatus::Paused->isEnded());
    }

    #[Test]
    public function it_reports_past_due_as_not_ended(): void
    {
        $this->assertFalse(SubscriptionStatus::PastDue->isEnded());
    }

    #[Test]
    public function it_reports_incomplete_as_not_ended(): void
    {
        $this->assertFalse(SubscriptionStatus::Incomplete->isEnded());
    }
}

<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Tests\Unit\Enums;

use LaravelPlus\PaymentGateway\Enums\PaymentStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PaymentStatusTest extends TestCase
{
    #[Test]
    public function it_creates_pending_from_value(): void
    {
        $this->assertSame(PaymentStatus::Pending, PaymentStatus::from('pending'));
    }

    #[Test]
    public function it_creates_processing_from_value(): void
    {
        $this->assertSame(PaymentStatus::Processing, PaymentStatus::from('processing'));
    }

    #[Test]
    public function it_creates_requires_action_from_value(): void
    {
        $this->assertSame(PaymentStatus::RequiresAction, PaymentStatus::from('requires_action'));
    }

    #[Test]
    public function it_creates_requires_capture_from_value(): void
    {
        $this->assertSame(PaymentStatus::RequiresCapture, PaymentStatus::from('requires_capture'));
    }

    #[Test]
    public function it_creates_succeeded_from_value(): void
    {
        $this->assertSame(PaymentStatus::Succeeded, PaymentStatus::from('succeeded'));
    }

    #[Test]
    public function it_creates_failed_from_value(): void
    {
        $this->assertSame(PaymentStatus::Failed, PaymentStatus::from('failed'));
    }

    #[Test]
    public function it_creates_canceled_from_value(): void
    {
        $this->assertSame(PaymentStatus::Canceled, PaymentStatus::from('canceled'));
    }

    #[Test]
    public function it_creates_refunded_from_value(): void
    {
        $this->assertSame(PaymentStatus::Refunded, PaymentStatus::from('refunded'));
    }

    #[Test]
    public function it_creates_partially_refunded_from_value(): void
    {
        $this->assertSame(PaymentStatus::PartiallyRefunded, PaymentStatus::from('partially_refunded'));
    }

    #[Test]
    public function it_creates_disputed_from_value(): void
    {
        $this->assertSame(PaymentStatus::Disputed, PaymentStatus::from('disputed'));
    }

    #[Test]
    public function it_creates_expired_from_value(): void
    {
        $this->assertSame(PaymentStatus::Expired, PaymentStatus::from('expired'));
    }

    #[Test]
    public function it_returns_correct_label_for_pending(): void
    {
        $this->assertSame('Pending', PaymentStatus::Pending->label());
    }

    #[Test]
    public function it_returns_correct_label_for_processing(): void
    {
        $this->assertSame('Processing', PaymentStatus::Processing->label());
    }

    #[Test]
    public function it_returns_correct_label_for_requires_action(): void
    {
        $this->assertSame('Requires Action', PaymentStatus::RequiresAction->label());
    }

    #[Test]
    public function it_returns_correct_label_for_requires_capture(): void
    {
        $this->assertSame('Requires Capture', PaymentStatus::RequiresCapture->label());
    }

    #[Test]
    public function it_returns_correct_label_for_succeeded(): void
    {
        $this->assertSame('Succeeded', PaymentStatus::Succeeded->label());
    }

    #[Test]
    public function it_returns_correct_label_for_failed(): void
    {
        $this->assertSame('Failed', PaymentStatus::Failed->label());
    }

    #[Test]
    public function it_returns_correct_label_for_canceled(): void
    {
        $this->assertSame('Canceled', PaymentStatus::Canceled->label());
    }

    #[Test]
    public function it_returns_correct_label_for_refunded(): void
    {
        $this->assertSame('Refunded', PaymentStatus::Refunded->label());
    }

    #[Test]
    public function it_returns_correct_label_for_partially_refunded(): void
    {
        $this->assertSame('Partially Refunded', PaymentStatus::PartiallyRefunded->label());
    }

    #[Test]
    public function it_returns_correct_label_for_disputed(): void
    {
        $this->assertSame('Disputed', PaymentStatus::Disputed->label());
    }

    #[Test]
    public function it_returns_correct_label_for_expired(): void
    {
        $this->assertSame('Expired', PaymentStatus::Expired->label());
    }

    #[Test]
    public function it_returns_yellow_color_for_pending(): void
    {
        $this->assertSame('yellow', PaymentStatus::Pending->color());
    }

    #[Test]
    public function it_returns_yellow_color_for_processing(): void
    {
        $this->assertSame('yellow', PaymentStatus::Processing->color());
    }

    #[Test]
    public function it_returns_yellow_color_for_requires_action(): void
    {
        $this->assertSame('yellow', PaymentStatus::RequiresAction->color());
    }

    #[Test]
    public function it_returns_yellow_color_for_requires_capture(): void
    {
        $this->assertSame('yellow', PaymentStatus::RequiresCapture->color());
    }

    #[Test]
    public function it_returns_green_color_for_succeeded(): void
    {
        $this->assertSame('green', PaymentStatus::Succeeded->color());
    }

    #[Test]
    public function it_returns_red_color_for_failed(): void
    {
        $this->assertSame('red', PaymentStatus::Failed->color());
    }

    #[Test]
    public function it_returns_red_color_for_canceled(): void
    {
        $this->assertSame('red', PaymentStatus::Canceled->color());
    }

    #[Test]
    public function it_returns_red_color_for_expired(): void
    {
        $this->assertSame('red', PaymentStatus::Expired->color());
    }

    #[Test]
    public function it_returns_blue_color_for_refunded(): void
    {
        $this->assertSame('blue', PaymentStatus::Refunded->color());
    }

    #[Test]
    public function it_returns_blue_color_for_partially_refunded(): void
    {
        $this->assertSame('blue', PaymentStatus::PartiallyRefunded->color());
    }

    #[Test]
    public function it_returns_orange_color_for_disputed(): void
    {
        $this->assertSame('orange', PaymentStatus::Disputed->color());
    }

    #[Test]
    public function it_reports_succeeded_as_successful(): void
    {
        $this->assertTrue(PaymentStatus::Succeeded->isSuccessful());
    }

    #[Test]
    public function it_reports_pending_as_not_successful(): void
    {
        $this->assertFalse(PaymentStatus::Pending->isSuccessful());
    }

    #[Test]
    public function it_reports_failed_as_not_successful(): void
    {
        $this->assertFalse(PaymentStatus::Failed->isSuccessful());
    }

    #[Test]
    public function it_reports_refunded_as_not_successful(): void
    {
        $this->assertFalse(PaymentStatus::Refunded->isSuccessful());
    }

    #[Test]
    public function it_reports_pending_as_pending(): void
    {
        $this->assertTrue(PaymentStatus::Pending->isPending());
    }

    #[Test]
    public function it_reports_processing_as_pending(): void
    {
        $this->assertTrue(PaymentStatus::Processing->isPending());
    }

    #[Test]
    public function it_reports_requires_action_as_pending(): void
    {
        $this->assertTrue(PaymentStatus::RequiresAction->isPending());
    }

    #[Test]
    public function it_reports_requires_capture_as_pending(): void
    {
        $this->assertTrue(PaymentStatus::RequiresCapture->isPending());
    }

    #[Test]
    public function it_reports_succeeded_as_not_pending(): void
    {
        $this->assertFalse(PaymentStatus::Succeeded->isPending());
    }

    #[Test]
    public function it_reports_failed_as_not_pending(): void
    {
        $this->assertFalse(PaymentStatus::Failed->isPending());
    }

    #[Test]
    public function it_reports_failed_as_failed(): void
    {
        $this->assertTrue(PaymentStatus::Failed->isFailed());
    }

    #[Test]
    public function it_reports_canceled_as_failed(): void
    {
        $this->assertTrue(PaymentStatus::Canceled->isFailed());
    }

    #[Test]
    public function it_reports_expired_as_failed(): void
    {
        $this->assertTrue(PaymentStatus::Expired->isFailed());
    }

    #[Test]
    public function it_reports_succeeded_as_not_failed(): void
    {
        $this->assertFalse(PaymentStatus::Succeeded->isFailed());
    }

    #[Test]
    public function it_reports_pending_as_not_failed(): void
    {
        $this->assertFalse(PaymentStatus::Pending->isFailed());
    }

    #[Test]
    public function it_reports_refunded_as_not_failed(): void
    {
        $this->assertFalse(PaymentStatus::Refunded->isFailed());
    }

    #[Test]
    public function it_reports_succeeded_as_final(): void
    {
        $this->assertTrue(PaymentStatus::Succeeded->isFinal());
    }

    #[Test]
    public function it_reports_failed_as_final(): void
    {
        $this->assertTrue(PaymentStatus::Failed->isFinal());
    }

    #[Test]
    public function it_reports_canceled_as_final(): void
    {
        $this->assertTrue(PaymentStatus::Canceled->isFinal());
    }

    #[Test]
    public function it_reports_refunded_as_final(): void
    {
        $this->assertTrue(PaymentStatus::Refunded->isFinal());
    }

    #[Test]
    public function it_reports_expired_as_final(): void
    {
        $this->assertTrue(PaymentStatus::Expired->isFinal());
    }

    #[Test]
    public function it_reports_pending_as_not_final(): void
    {
        $this->assertFalse(PaymentStatus::Pending->isFinal());
    }

    #[Test]
    public function it_reports_processing_as_not_final(): void
    {
        $this->assertFalse(PaymentStatus::Processing->isFinal());
    }

    #[Test]
    public function it_reports_disputed_as_not_final(): void
    {
        $this->assertFalse(PaymentStatus::Disputed->isFinal());
    }

    #[Test]
    public function it_reports_partially_refunded_as_not_final(): void
    {
        $this->assertFalse(PaymentStatus::PartiallyRefunded->isFinal());
    }
}

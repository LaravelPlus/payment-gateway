<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Tests\Unit\Enums;

use LaravelPlus\PaymentGateway\Enums\InvoiceStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InvoiceStatusTest extends TestCase
{
    #[Test]
    public function it_creates_draft_from_value(): void
    {
        $this->assertSame(InvoiceStatus::Draft, InvoiceStatus::from('draft'));
    }

    #[Test]
    public function it_creates_open_from_value(): void
    {
        $this->assertSame(InvoiceStatus::Open, InvoiceStatus::from('open'));
    }

    #[Test]
    public function it_creates_paid_from_value(): void
    {
        $this->assertSame(InvoiceStatus::Paid, InvoiceStatus::from('paid'));
    }

    #[Test]
    public function it_creates_void_from_value(): void
    {
        $this->assertSame(InvoiceStatus::Void, InvoiceStatus::from('void'));
    }

    #[Test]
    public function it_creates_uncollectible_from_value(): void
    {
        $this->assertSame(InvoiceStatus::Uncollectible, InvoiceStatus::from('uncollectible'));
    }

    #[Test]
    public function it_returns_correct_label_for_draft(): void
    {
        $this->assertSame('Draft', InvoiceStatus::Draft->label());
    }

    #[Test]
    public function it_returns_correct_label_for_open(): void
    {
        $this->assertSame('Open', InvoiceStatus::Open->label());
    }

    #[Test]
    public function it_returns_correct_label_for_paid(): void
    {
        $this->assertSame('Paid', InvoiceStatus::Paid->label());
    }

    #[Test]
    public function it_returns_correct_label_for_void(): void
    {
        $this->assertSame('Void', InvoiceStatus::Void->label());
    }

    #[Test]
    public function it_returns_correct_label_for_uncollectible(): void
    {
        $this->assertSame('Uncollectible', InvoiceStatus::Uncollectible->label());
    }

    #[Test]
    public function it_returns_gray_color_for_draft(): void
    {
        $this->assertSame('gray', InvoiceStatus::Draft->color());
    }

    #[Test]
    public function it_returns_blue_color_for_open(): void
    {
        $this->assertSame('blue', InvoiceStatus::Open->color());
    }

    #[Test]
    public function it_returns_green_color_for_paid(): void
    {
        $this->assertSame('green', InvoiceStatus::Paid->color());
    }

    #[Test]
    public function it_returns_red_color_for_void(): void
    {
        $this->assertSame('red', InvoiceStatus::Void->color());
    }

    #[Test]
    public function it_returns_orange_color_for_uncollectible(): void
    {
        $this->assertSame('orange', InvoiceStatus::Uncollectible->color());
    }

    #[Test]
    public function it_reports_draft_as_editable(): void
    {
        $this->assertTrue(InvoiceStatus::Draft->isEditable());
    }

    #[Test]
    public function it_reports_open_as_not_editable(): void
    {
        $this->assertFalse(InvoiceStatus::Open->isEditable());
    }

    #[Test]
    public function it_reports_paid_as_not_editable(): void
    {
        $this->assertFalse(InvoiceStatus::Paid->isEditable());
    }

    #[Test]
    public function it_reports_void_as_not_editable(): void
    {
        $this->assertFalse(InvoiceStatus::Void->isEditable());
    }

    #[Test]
    public function it_reports_uncollectible_as_not_editable(): void
    {
        $this->assertFalse(InvoiceStatus::Uncollectible->isEditable());
    }

    #[Test]
    public function it_reports_draft_as_not_finalized(): void
    {
        $this->assertFalse(InvoiceStatus::Draft->isFinalized());
    }

    #[Test]
    public function it_reports_open_as_finalized(): void
    {
        $this->assertTrue(InvoiceStatus::Open->isFinalized());
    }

    #[Test]
    public function it_reports_paid_as_finalized(): void
    {
        $this->assertTrue(InvoiceStatus::Paid->isFinalized());
    }

    #[Test]
    public function it_reports_void_as_finalized(): void
    {
        $this->assertTrue(InvoiceStatus::Void->isFinalized());
    }

    #[Test]
    public function it_reports_uncollectible_as_finalized(): void
    {
        $this->assertTrue(InvoiceStatus::Uncollectible->isFinalized());
    }

    #[Test]
    public function it_returns_all_values_as_array(): void
    {
        $expected = ['draft', 'open', 'paid', 'void', 'uncollectible'];

        $this->assertSame($expected, InvoiceStatus::values());
    }
}

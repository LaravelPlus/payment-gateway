<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Tests\Unit\Enums;

use LaravelPlus\PaymentGateway\Enums\PaymentDriver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PaymentDriverTest extends TestCase
{
    #[Test]
    public function it_creates_stripe_from_value(): void
    {
        $this->assertSame(PaymentDriver::Stripe, PaymentDriver::from('stripe'));
    }

    #[Test]
    public function it_creates_paypal_from_value(): void
    {
        $this->assertSame(PaymentDriver::PayPal, PaymentDriver::from('paypal'));
    }

    #[Test]
    public function it_creates_crypto_from_value(): void
    {
        $this->assertSame(PaymentDriver::Crypto, PaymentDriver::from('crypto'));
    }

    #[Test]
    public function it_creates_bank_transfer_from_value(): void
    {
        $this->assertSame(PaymentDriver::BankTransfer, PaymentDriver::from('bank_transfer'));
    }

    #[Test]
    public function it_creates_cash_on_delivery_from_value(): void
    {
        $this->assertSame(PaymentDriver::CashOnDelivery, PaymentDriver::from('cash_on_delivery'));
    }

    #[Test]
    public function it_returns_correct_label_for_stripe(): void
    {
        $this->assertSame('Credit Card (Stripe)', PaymentDriver::Stripe->label());
    }

    #[Test]
    public function it_returns_correct_label_for_paypal(): void
    {
        $this->assertSame('PayPal', PaymentDriver::PayPal->label());
    }

    #[Test]
    public function it_returns_correct_label_for_crypto(): void
    {
        $this->assertSame('Cryptocurrency', PaymentDriver::Crypto->label());
    }

    #[Test]
    public function it_returns_correct_label_for_bank_transfer(): void
    {
        $this->assertSame('Bank Transfer', PaymentDriver::BankTransfer->label());
    }

    #[Test]
    public function it_returns_correct_label_for_cash_on_delivery(): void
    {
        $this->assertSame('Cash on Delivery', PaymentDriver::CashOnDelivery->label());
    }

    #[Test]
    public function it_returns_correct_icon_for_stripe(): void
    {
        $this->assertSame('credit-card', PaymentDriver::Stripe->icon());
    }

    #[Test]
    public function it_returns_correct_icon_for_paypal(): void
    {
        $this->assertSame('paypal', PaymentDriver::PayPal->icon());
    }

    #[Test]
    public function it_returns_correct_icon_for_crypto(): void
    {
        $this->assertSame('bitcoin', PaymentDriver::Crypto->icon());
    }

    #[Test]
    public function it_returns_correct_icon_for_bank_transfer(): void
    {
        $this->assertSame('building-columns', PaymentDriver::BankTransfer->icon());
    }

    #[Test]
    public function it_returns_correct_icon_for_cash_on_delivery(): void
    {
        $this->assertSame('hand-holding-dollar', PaymentDriver::CashOnDelivery->icon());
    }

    #[Test]
    public function it_reports_stripe_supports_subscriptions(): void
    {
        $this->assertTrue(PaymentDriver::Stripe->supportsSubscriptions());
    }

    #[Test]
    public function it_reports_paypal_supports_subscriptions(): void
    {
        $this->assertTrue(PaymentDriver::PayPal->supportsSubscriptions());
    }

    #[Test]
    public function it_reports_crypto_supports_subscriptions(): void
    {
        $this->assertTrue(PaymentDriver::Crypto->supportsSubscriptions());
    }

    #[Test]
    public function it_reports_bank_transfer_does_not_support_subscriptions(): void
    {
        $this->assertFalse(PaymentDriver::BankTransfer->supportsSubscriptions());
    }

    #[Test]
    public function it_reports_cash_on_delivery_does_not_support_subscriptions(): void
    {
        $this->assertFalse(PaymentDriver::CashOnDelivery->supportsSubscriptions());
    }

    #[Test]
    public function it_reports_stripe_supports_refunds(): void
    {
        $this->assertTrue(PaymentDriver::Stripe->supportsRefunds());
    }

    #[Test]
    public function it_reports_paypal_supports_refunds(): void
    {
        $this->assertTrue(PaymentDriver::PayPal->supportsRefunds());
    }

    #[Test]
    public function it_reports_crypto_does_not_support_refunds(): void
    {
        $this->assertFalse(PaymentDriver::Crypto->supportsRefunds());
    }

    #[Test]
    public function it_reports_bank_transfer_does_not_support_refunds(): void
    {
        $this->assertFalse(PaymentDriver::BankTransfer->supportsRefunds());
    }

    #[Test]
    public function it_reports_cash_on_delivery_does_not_support_refunds(): void
    {
        $this->assertFalse(PaymentDriver::CashOnDelivery->supportsRefunds());
    }

    #[Test]
    public function it_reports_stripe_is_instant(): void
    {
        $this->assertTrue(PaymentDriver::Stripe->isInstant());
    }

    #[Test]
    public function it_reports_paypal_is_instant(): void
    {
        $this->assertTrue(PaymentDriver::PayPal->isInstant());
    }

    #[Test]
    public function it_reports_crypto_is_not_instant(): void
    {
        $this->assertFalse(PaymentDriver::Crypto->isInstant());
    }

    #[Test]
    public function it_reports_bank_transfer_is_not_instant(): void
    {
        $this->assertFalse(PaymentDriver::BankTransfer->isInstant());
    }

    #[Test]
    public function it_reports_cash_on_delivery_is_not_instant(): void
    {
        $this->assertFalse(PaymentDriver::CashOnDelivery->isInstant());
    }

    #[Test]
    public function it_reports_bank_transfer_requires_manual_confirmation(): void
    {
        $this->assertTrue(PaymentDriver::BankTransfer->requiresManualConfirmation());
    }

    #[Test]
    public function it_reports_cash_on_delivery_requires_manual_confirmation(): void
    {
        $this->assertTrue(PaymentDriver::CashOnDelivery->requiresManualConfirmation());
    }

    #[Test]
    public function it_reports_crypto_requires_manual_confirmation(): void
    {
        $this->assertTrue(PaymentDriver::Crypto->requiresManualConfirmation());
    }

    #[Test]
    public function it_reports_stripe_does_not_require_manual_confirmation(): void
    {
        $this->assertFalse(PaymentDriver::Stripe->requiresManualConfirmation());
    }

    #[Test]
    public function it_reports_paypal_does_not_require_manual_confirmation(): void
    {
        $this->assertFalse(PaymentDriver::PayPal->requiresManualConfirmation());
    }
}

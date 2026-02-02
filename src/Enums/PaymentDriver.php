<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Enums;

enum PaymentDriver: string
{
    case Stripe = 'stripe';
    case PayPal = 'paypal';
    case Crypto = 'crypto';
    case BankTransfer = 'bank_transfer';
    case CashOnDelivery = 'cash_on_delivery';

    /**
     * Get human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Stripe => 'Credit Card (Stripe)',
            self::PayPal => 'PayPal',
            self::Crypto => 'Cryptocurrency',
            self::BankTransfer => 'Bank Transfer',
            self::CashOnDelivery => 'Cash on Delivery',
        };
    }

    /**
     * Get icon name for UI.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Stripe => 'credit-card',
            self::PayPal => 'paypal',
            self::Crypto => 'bitcoin',
            self::BankTransfer => 'building-columns',
            self::CashOnDelivery => 'hand-holding-dollar',
        };
    }

    /**
     * Check if driver supports subscriptions.
     */
    public function supportsSubscriptions(): bool
    {
        return in_array($this, [
            self::Stripe,
            self::PayPal,
            self::Crypto,
        ]);
    }

    /**
     * Check if driver supports refunds.
     */
    public function supportsRefunds(): bool
    {
        return in_array($this, [
            self::Stripe,
            self::PayPal,
        ]);
    }

    /**
     * Check if driver is instant (immediate confirmation).
     */
    public function isInstant(): bool
    {
        return in_array($this, [
            self::Stripe,
            self::PayPal,
        ]);
    }

    /**
     * Check if driver requires manual confirmation.
     */
    public function requiresManualConfirmation(): bool
    {
        return in_array($this, [
            self::BankTransfer,
            self::CashOnDelivery,
            self::Crypto,
        ]);
    }
}

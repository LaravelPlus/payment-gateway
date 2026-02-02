<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Enums;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Paid = 'paid';
    case Void = 'void';
    case Uncollectible = 'uncollectible';

    /**
     * Get human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Open => 'Open',
            self::Paid => 'Paid',
            self::Void => 'Void',
            self::Uncollectible => 'Uncollectible',
        };
    }

    /**
     * Get status color for UI.
     */
    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Open => 'blue',
            self::Paid => 'green',
            self::Void => 'red',
            self::Uncollectible => 'orange',
        };
    }

    /**
     * Check if invoice can be edited.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Check if invoice is finalized.
     */
    public function isFinalized(): bool
    {
        return $this !== self::Draft;
    }

    /**
     * Get all values as array.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

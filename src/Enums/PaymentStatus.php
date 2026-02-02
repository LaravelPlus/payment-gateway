<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case RequiresAction = 'requires_action';
    case RequiresCapture = 'requires_capture';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Canceled = 'canceled';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';
    case Disputed = 'disputed';
    case Expired = 'expired';

    /**
     * Check if payment is successful.
     */
    public function isSuccessful(): bool
    {
        return $this === self::Succeeded;
    }

    /**
     * Check if payment is pending/processing.
     */
    public function isPending(): bool
    {
        return in_array($this, [
            self::Pending,
            self::Processing,
            self::RequiresAction,
            self::RequiresCapture,
        ]);
    }

    /**
     * Check if payment has failed.
     */
    public function isFailed(): bool
    {
        return in_array($this, [
            self::Failed,
            self::Canceled,
            self::Expired,
        ]);
    }

    /**
     * Check if payment is final (no more changes expected).
     */
    public function isFinal(): bool
    {
        return in_array($this, [
            self::Succeeded,
            self::Failed,
            self::Canceled,
            self::Refunded,
            self::Expired,
        ]);
    }

    /**
     * Get human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Processing => 'Processing',
            self::RequiresAction => 'Requires Action',
            self::RequiresCapture => 'Requires Capture',
            self::Succeeded => 'Succeeded',
            self::Failed => 'Failed',
            self::Canceled => 'Canceled',
            self::Refunded => 'Refunded',
            self::PartiallyRefunded => 'Partially Refunded',
            self::Disputed => 'Disputed',
            self::Expired => 'Expired',
        };
    }

    /**
     * Get color for UI display.
     */
    public function color(): string
    {
        return match ($this) {
            self::Pending, self::Processing, self::RequiresAction, self::RequiresCapture => 'yellow',
            self::Succeeded => 'green',
            self::Failed, self::Canceled, self::Expired => 'red',
            self::Refunded, self::PartiallyRefunded => 'blue',
            self::Disputed => 'orange',
        };
    }
}

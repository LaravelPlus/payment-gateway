<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Enums;

enum SubscriptionStatus: string
{
    case Active = 'active';
    case Trialing = 'trialing';
    case PastDue = 'past_due';
    case Paused = 'paused';
    case Canceled = 'canceled';
    case Unpaid = 'unpaid';
    case Incomplete = 'incomplete';
    case IncompleteExpired = 'incomplete_expired';

    /**
     * Check if subscription is active.
     */
    public function isActive(): bool
    {
        return in_array($this, [
            self::Active,
            self::Trialing,
        ]);
    }

    /**
     * Check if subscription is in a grace period.
     */
    public function isGracePeriod(): bool
    {
        return in_array($this, [
            self::PastDue,
            self::Unpaid,
        ]);
    }

    /**
     * Check if subscription is ended.
     */
    public function isEnded(): bool
    {
        return in_array($this, [
            self::Canceled,
            self::IncompleteExpired,
        ]);
    }

    /**
     * Get human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Trialing => 'Trial',
            self::PastDue => 'Past Due',
            self::Paused => 'Paused',
            self::Canceled => 'Canceled',
            self::Unpaid => 'Unpaid',
            self::Incomplete => 'Incomplete',
            self::IncompleteExpired => 'Expired',
        };
    }

    /**
     * Get color for UI display.
     */
    public function color(): string
    {
        return match ($this) {
            self::Active => 'green',
            self::Trialing => 'blue',
            self::PastDue, self::Unpaid => 'yellow',
            self::Paused => 'gray',
            self::Canceled, self::IncompleteExpired => 'red',
            self::Incomplete => 'orange',
        };
    }
}

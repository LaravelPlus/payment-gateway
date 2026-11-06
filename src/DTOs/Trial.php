<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\DTOs;

use DateTimeInterface;
use LaravelPlus\PaymentGateway\Exceptions\PaymentException;

/**
 * A subscription-wide free trial, validated before any provider request.
 *
 * Options read (all optional; no trial unless one is asked for):
 *  - trial_days          int > 0. Canonical name.
 *  - trial_period_days   deprecated alias of trial_days (old Checkout option).
 *  - trial_end           DateTimeInterface|int unix timestamp. Exclusive with trial_days.
 *  - trial_end_behavior  cancel|pause|create_invoice — what happens when the trial
 *                        ends without a payment method. Defaults to
 *                        config('payment-gateway.subscriptions.trial_end_behavior'),
 *                        and when that is null Stripe's own default (create_invoice).
 *
 * null or 0 days means "no trial", so existing callers passing $plan->trial_days keep working.
 */
final readonly class Trial
{
    public const END_BEHAVIORS = ['cancel', 'pause', 'create_invoice'];

    /** Stripe rejects trials longer than two years. */
    public const PROVIDER_MAX_DAYS = 730;

    private function __construct(
        public ?int $days,
        public ?int $endsAt,
        public ?string $endBehavior,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @param  int  $minLeadSeconds  how far in the future trial_end must be (Checkout needs 48h)
     */
    public static function fromOptions(array $options, int $minLeadSeconds = 0): ?self
    {
        $days = self::days($options);
        $endsAt = self::endsAt($options['trial_end'] ?? null, $minLeadSeconds);

        if ($days !== null && $endsAt !== null) {
            throw new PaymentException('Pass either trial_days or trial_end, not both.');
        }

        if ($days === null && $endsAt === null) {
            return null;
        }

        $behavior = $options['trial_end_behavior'] ?? config('payment-gateway.subscriptions.trial_end_behavior');
        if ($behavior !== null && !in_array($behavior, self::END_BEHAVIORS, true)) {
            throw new PaymentException('trial_end_behavior must be one of: '.implode(', ', self::END_BEHAVIORS).'.');
        }

        return new self($days, $endsAt, $behavior);
    }

    public static function maxDays(): int
    {
        return min(self::PROVIDER_MAX_DAYS, (int) config('payment-gateway.subscriptions.max_trial_days', self::PROVIDER_MAX_DAYS));
    }

    /**
     * Stripe params for subscriptions.create or checkout subscription_data.
     *
     * @return array<string, mixed>
     */
    public function toStripe(): array
    {
        $params = $this->days !== null
            ? ['trial_period_days' => $this->days]
            : ['trial_end' => $this->endsAt];

        if ($this->endBehavior !== null) {
            $params['trial_settings'] = ['end_behavior' => ['missing_payment_method' => $this->endBehavior]];
        }

        return $params;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private static function days(array $options): ?int
    {
        $canonical = self::int($options['trial_days'] ?? null, 'trial_days');
        $legacy = self::int($options['trial_period_days'] ?? null, 'trial_period_days');

        if ($canonical !== null && $legacy !== null && $canonical !== $legacy) {
            throw new PaymentException('trial_days and trial_period_days disagree; pass only trial_days.');
        }

        $days = $canonical ?? $legacy;

        if ($days === null || $days === 0) {
            return null;
        }

        if ($days < 0 || $days > self::maxDays()) {
            throw new PaymentException('Trial length must be between 1 and '.self::maxDays().' days.');
        }

        return $days;
    }

    private static function int(mixed $value, string $name): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            $value = (int) $value;
        }

        if (!is_int($value)) {
            throw new PaymentException("{$name} must be a whole number of days.");
        }

        return $value;
    }

    private static function endsAt(mixed $value, int $minLeadSeconds): ?int
    {
        if ($value === null) {
            return null;
        }

        $ts = match (true) {
            $value instanceof DateTimeInterface => $value->getTimestamp(),
            is_int($value) => $value,
            default => throw new PaymentException('trial_end must be a DateTimeInterface or unix timestamp.'),
        };

        $now = time();
        if ($ts <= $now + $minLeadSeconds || $ts > $now + self::maxDays() * 86400) {
            throw new PaymentException('trial_end is outside the allowed window.');
        }

        return $ts;
    }
}

<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\DTOs;

use DateTimeImmutable;
use DateTimeInterface;
use Nejcc\PaymentGateway\Enums\SubscriptionStatus;

/**
 * Subscription data transfer object.
 *
 * All monetary amounts are in cents (smallest currency unit).
 */
final readonly class Subscription
{
    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $id,
        public string $customerId,
        public string $planId,
        public SubscriptionStatus $status,
        public int $amount,
        public string $currency,
        public string $interval,
        public string $driver,
        public ?DateTimeInterface $currentPeriodStart = null,
        public ?DateTimeInterface $currentPeriodEnd = null,
        public ?DateTimeInterface $trialStart = null,
        public ?DateTimeInterface $trialEnd = null,
        public ?DateTimeInterface $canceledAt = null,
        public ?DateTimeInterface $endedAt = null,
        public bool $cancelAtPeriodEnd = false,
        public array $metadata = [],
        public array $raw = [],
    ) {}

    /**
     * Create from array.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            customerId: $data['customer_id'],
            planId: $data['plan_id'],
            status: $data['status'] instanceof SubscriptionStatus
                ? $data['status']
                : SubscriptionStatus::from($data['status']),
            amount: (int) $data['amount'],
            currency: mb_strtoupper($data['currency']),
            interval: $data['interval'],
            driver: $data['driver'],
            currentPeriodStart: isset($data['current_period_start'])
                ? new DateTimeImmutable($data['current_period_start'])
                : null,
            currentPeriodEnd: isset($data['current_period_end'])
                ? new DateTimeImmutable($data['current_period_end'])
                : null,
            trialStart: isset($data['trial_start'])
                ? new DateTimeImmutable($data['trial_start'])
                : null,
            trialEnd: isset($data['trial_end'])
                ? new DateTimeImmutable($data['trial_end'])
                : null,
            canceledAt: isset($data['canceled_at'])
                ? new DateTimeImmutable($data['canceled_at'])
                : null,
            endedAt: isset($data['ended_at'])
                ? new DateTimeImmutable($data['ended_at'])
                : null,
            cancelAtPeriodEnd: $data['cancel_at_period_end'] ?? false,
            metadata: $data['metadata'] ?? [],
            raw: $data['raw'] ?? [],
        );
    }

    /**
     * Check if subscription is active.
     */
    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /**
     * Check if subscription is on trial.
     */
    public function onTrial(): bool
    {
        return $this->status === SubscriptionStatus::Trialing
            && $this->trialEnd !== null
            && $this->trialEnd > new DateTimeImmutable();
    }

    /**
     * Check if subscription is canceled.
     */
    public function isCanceled(): bool
    {
        return $this->canceledAt !== null || $this->status === SubscriptionStatus::Canceled;
    }

    /**
     * Get days remaining in current period.
     */
    public function daysRemaining(): int
    {
        if ($this->currentPeriodEnd === null) {
            return 0;
        }

        $now = new DateTimeImmutable();
        $diff = $now->diff($this->currentPeriodEnd);

        return $diff->invert ? 0 : $diff->days;
    }

    /**
     * Get amount in decimal format.
     */
    public function getAmountDecimal(): float
    {
        return $this->amount / 100;
    }

    /**
     * Convert to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customerId,
            'plan_id' => $this->planId,
            'status' => $this->status->value,
            'amount' => $this->amount,
            'amount_decimal' => $this->getAmountDecimal(),
            'currency' => $this->currency,
            'interval' => $this->interval,
            'driver' => $this->driver,
            'current_period_start' => $this->currentPeriodStart?->format('c'),
            'current_period_end' => $this->currentPeriodEnd?->format('c'),
            'trial_start' => $this->trialStart?->format('c'),
            'trial_end' => $this->trialEnd?->format('c'),
            'canceled_at' => $this->canceledAt?->format('c'),
            'ended_at' => $this->endedAt?->format('c'),
            'cancel_at_period_end' => $this->cancelAtPeriodEnd,
            'metadata' => $this->metadata,
        ];
    }
}

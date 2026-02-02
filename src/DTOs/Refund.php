<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\DTOs;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Refund data transfer object.
 *
 * All monetary amounts are in cents (smallest currency unit).
 */
final readonly class Refund
{
    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $id,
        public string $transactionId,
        public string $status,
        public int $amount,
        public string $currency,
        public string $driver,
        public ?string $reason = null,
        public ?string $failureReason = null,
        public ?DateTimeInterface $createdAt = null,
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
            transactionId: $data['transaction_id'],
            status: $data['status'],
            amount: (int) $data['amount'],
            currency: mb_strtoupper($data['currency']),
            driver: $data['driver'],
            reason: $data['reason'] ?? null,
            failureReason: $data['failure_reason'] ?? null,
            createdAt: isset($data['created_at'])
                ? new DateTimeImmutable($data['created_at'])
                : null,
            metadata: $data['metadata'] ?? [],
            raw: $data['raw'] ?? [],
        );
    }

    /**
     * Check if refund succeeded.
     */
    public function isSuccessful(): bool
    {
        return $this->status === 'succeeded';
    }

    /**
     * Check if refund is pending.
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if refund failed.
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
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
            'transaction_id' => $this->transactionId,
            'status' => $this->status,
            'amount' => $this->amount,
            'amount_decimal' => $this->getAmountDecimal(),
            'currency' => $this->currency,
            'driver' => $this->driver,
            'reason' => $this->reason,
            'failure_reason' => $this->failureReason,
            'created_at' => $this->createdAt?->format('c'),
            'metadata' => $this->metadata,
        ];
    }
}

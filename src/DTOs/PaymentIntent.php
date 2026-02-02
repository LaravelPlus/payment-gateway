<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\DTOs;

use DateTimeImmutable;
use DateTimeInterface;
use Nejcc\PaymentGateway\Enums\PaymentStatus;

/**
 * Payment intent data transfer object.
 *
 * All monetary amounts are in cents (smallest currency unit).
 * Example: $10.00 = 1000 cents
 */
final readonly class PaymentIntent
{
    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $id,
        public string $clientSecret,
        public PaymentStatus $status,
        public int $amount,
        public string $currency,
        public string $driver,
        public ?string $customerId = null,
        public ?string $returnUrl = null,
        public ?string $cancelUrl = null,
        public ?DateTimeInterface $expiresAt = null,
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
            clientSecret: $data['client_secret'],
            status: $data['status'] instanceof PaymentStatus
                ? $data['status']
                : PaymentStatus::from($data['status']),
            amount: (int) $data['amount'],
            currency: mb_strtoupper($data['currency']),
            driver: $data['driver'],
            customerId: $data['customer_id'] ?? null,
            returnUrl: $data['return_url'] ?? null,
            cancelUrl: $data['cancel_url'] ?? null,
            expiresAt: isset($data['expires_at'])
                ? new DateTimeImmutable($data['expires_at'])
                : null,
            metadata: $data['metadata'] ?? [],
            raw: $data['raw'] ?? [],
        );
    }

    /**
     * Get amount in decimal format.
     */
    public function getAmountDecimal(): float
    {
        return $this->amount / 100;
    }

    /**
     * Check if the intent has expired.
     */
    public function isExpired(): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return $this->expiresAt < new DateTimeImmutable();
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
            'client_secret' => $this->clientSecret,
            'status' => $this->status->value,
            'amount' => $this->amount,
            'amount_decimal' => $this->getAmountDecimal(),
            'currency' => $this->currency,
            'driver' => $this->driver,
            'customer_id' => $this->customerId,
            'return_url' => $this->returnUrl,
            'cancel_url' => $this->cancelUrl,
            'expires_at' => $this->expiresAt?->format('c'),
            'metadata' => $this->metadata,
        ];
    }
}

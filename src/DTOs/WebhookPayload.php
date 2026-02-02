<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\DTOs;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Webhook payload data transfer object.
 */
final readonly class WebhookPayload
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $driver,
        public array $data,
        public ?DateTimeInterface $createdAt = null,
        public array $raw = [],
    ) {}

    /**
     * Create from array.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            id: $payload['id'],
            type: $payload['type'],
            driver: $payload['driver'],
            data: $payload['data'] ?? [],
            createdAt: isset($payload['created_at'])
                ? new DateTimeImmutable($payload['created_at'])
                : null,
            raw: $payload['raw'] ?? [],
        );
    }

    /**
     * Get a value from the data array using dot notation.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->data, $key, $default);
    }

    /**
     * Check if the webhook is for a specific event type.
     */
    public function isType(string $type): bool
    {
        return $this->type === $type;
    }

    /**
     * Check if the webhook matches any of the given types.
     *
     * @param  array<string>  $types
     */
    public function isAnyType(array $types): bool
    {
        return in_array($this->type, $types);
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
            'type' => $this->type,
            'driver' => $this->driver,
            'data' => $this->data,
            'created_at' => $this->createdAt?->format('c'),
        ];
    }
}

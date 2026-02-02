<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\DTOs;

/**
 * Address data transfer object.
 */
final readonly class Address
{
    public function __construct(
        public ?string $line1 = null,
        public ?string $line2 = null,
        public ?string $city = null,
        public ?string $state = null,
        public ?string $postalCode = null,
        public ?string $country = null,
    ) {}

    /**
     * Create from array.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            line1: $data['line1'] ?? $data['address_line1'] ?? null,
            line2: $data['line2'] ?? $data['address_line2'] ?? null,
            city: $data['city'] ?? null,
            state: $data['state'] ?? $data['region'] ?? $data['province'] ?? null,
            postalCode: $data['postal_code'] ?? $data['zip'] ?? $data['postcode'] ?? null,
            country: $data['country'] ?? $data['country_code'] ?? null,
        );
    }

    /**
     * Check if address is complete.
     */
    public function isComplete(): bool
    {
        return $this->line1 !== null
            && $this->city !== null
            && $this->postalCode !== null
            && $this->country !== null;
    }

    /**
     * Get formatted single-line address.
     */
    public function toSingleLine(): string
    {
        $parts = array_filter([
            $this->line1,
            $this->line2,
            $this->city,
            $this->state,
            $this->postalCode,
            $this->country,
        ]);

        return implode(', ', $parts);
    }

    /**
     * Convert to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'line1' => $this->line1,
            'line2' => $this->line2,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postalCode,
            'country' => $this->country,
        ], fn ($value) => $value !== null);
    }
}

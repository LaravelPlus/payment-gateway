<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\DTOs;

/**
 * Company data transfer object.
 */
final readonly class Company
{
    public function __construct(
        public ?string $name = null,
        public ?string $registrationNumber = null,
        public ?string $vatNumber = null,
        public ?string $taxId = null,
        public ?Address $address = null,
        public ?string $phone = null,
        public ?string $email = null,
        public ?string $website = null,
    ) {}

    /**
     * Create from array.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'] ?? null,
            registrationNumber: $data['registration_number'] ?? $data['company_number'] ?? null,
            vatNumber: $data['vat_number'] ?? $data['vat_id'] ?? null,
            taxId: $data['tax_id'] ?? null,
            address: isset($data['address']) ? Address::fromArray($data['address']) : null,
            phone: $data['phone'] ?? null,
            email: $data['email'] ?? null,
            website: $data['website'] ?? null,
        );
    }

    /**
     * Check if company has VAT number.
     */
    public function hasVatNumber(): bool
    {
        return $this->vatNumber !== null && $this->vatNumber !== '';
    }

    /**
     * Check if company info is complete for invoicing.
     */
    public function isCompleteForInvoicing(): bool
    {
        return $this->name !== null
            && $this->address !== null
            && $this->address->isComplete();
    }

    /**
     * Convert to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'registration_number' => $this->registrationNumber,
            'vat_number' => $this->vatNumber,
            'tax_id' => $this->taxId,
            'address' => $this->address?->toArray(),
            'phone' => $this->phone,
            'email' => $this->email,
            'website' => $this->website,
        ], fn ($value) => $value !== null);
    }
}

<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\DTOs;

/**
 * Customer data transfer object.
 */
final readonly class Customer
{
    /**
     * @param  array<Address>  $addresses
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public ?string $id = null,
        public ?string $email = null,
        public ?string $name = null,
        public ?string $phone = null,
        public ?Company $company = null,
        public ?Address $billingAddress = null,
        public ?Address $shippingAddress = null,
        public ?Address $invoiceAddress = null,
        public array $addresses = [],
        public ?string $taxId = null,
        public ?string $vatNumber = null,
        public ?string $defaultPaymentMethodId = null,
        public ?string $preferredLocale = null,
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
        $addresses = [];
        if (isset($data['addresses']) && is_array($data['addresses'])) {
            foreach ($data['addresses'] as $address) {
                $addresses[] = Address::fromArray($address);
            }
        }

        return new self(
            id: $data['id'] ?? null,
            email: $data['email'] ?? null,
            name: $data['name'] ?? null,
            phone: $data['phone'] ?? null,
            company: isset($data['company']) ? Company::fromArray($data['company']) : null,
            billingAddress: isset($data['billing_address']) ? Address::fromArray($data['billing_address']) : null,
            shippingAddress: isset($data['shipping_address']) ? Address::fromArray($data['shipping_address']) : null,
            invoiceAddress: isset($data['invoice_address']) ? Address::fromArray($data['invoice_address']) : null,
            addresses: $addresses,
            taxId: $data['tax_id'] ?? null,
            vatNumber: $data['vat_number'] ?? null,
            defaultPaymentMethodId: $data['default_payment_method_id'] ?? null,
            preferredLocale: $data['preferred_locale'] ?? null,
            metadata: $data['metadata'] ?? [],
            raw: $data['raw'] ?? [],
        );
    }

    /**
     * Create from a billable model (e.g., User).
     *
     * @param  object  $billable  The billable model with email and name properties
     */
    public static function fromBillable(object $billable): self
    {
        return new self(
            id: $billable->payment_customer_id ?? null,
            email: $billable->email ?? null,
            name: $billable->name ?? null,
            phone: $billable->phone ?? null,
            metadata: [
                'billable_type' => get_class($billable),
                'billable_id' => $billable->id ?? null,
            ],
        );
    }

    /**
     * Check if customer is a business.
     */
    public function isBusiness(): bool
    {
        return $this->company !== null || $this->vatNumber !== null;
    }

    /**
     * Get the effective invoice address (falls back to billing address).
     */
    public function getInvoiceAddress(): ?Address
    {
        return $this->invoiceAddress ?? $this->billingAddress;
    }

    /**
     * Get display name (company name or personal name).
     */
    public function getDisplayName(): ?string
    {
        if ($this->company !== null && $this->company->name !== null) {
            return $this->company->name;
        }

        return $this->name;
    }

    /**
     * Convert to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'id' => $this->id,
            'email' => $this->email,
            'name' => $this->name,
            'phone' => $this->phone,
            'company' => $this->company?->toArray(),
            'billing_address' => $this->billingAddress?->toArray(),
            'shipping_address' => $this->shippingAddress?->toArray(),
            'invoice_address' => $this->invoiceAddress?->toArray(),
            'addresses' => array_map(fn (Address $a) => $a->toArray(), $this->addresses),
            'tax_id' => $this->taxId,
            'vat_number' => $this->vatNumber,
            'default_payment_method_id' => $this->defaultPaymentMethodId,
            'preferred_locale' => $this->preferredLocale,
            'metadata' => $this->metadata,
        ], fn ($value) => $value !== null && $value !== []);
    }
}

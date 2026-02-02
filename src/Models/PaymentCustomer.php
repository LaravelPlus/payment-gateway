<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Nejcc\PaymentGateway\Database\Factories\PaymentCustomerFactory;
use Nejcc\PaymentGateway\DTOs\Address;
use Nejcc\PaymentGateway\DTOs\Company;
use Nejcc\PaymentGateway\DTOs\Customer;

final class PaymentCustomer extends Model
{
    /** @use HasFactory<PaymentCustomerFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'stripe_id',
        'paypal_id',
        'crypto_id',
        'email',
        'name',
        'phone',
        'preferred_locale',
        'tax_id',
        'vat_number',
        'company',
        'billing_address',
        'shipping_address',
        'invoice_address',
        'default_payment_method',
        'is_primary',
        'is_business',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'company' => 'array',
            'billing_address' => 'array',
            'shipping_address' => 'array',
            'invoice_address' => 'array',
            'metadata' => 'array',
            'is_primary' => 'boolean',
            'is_business' => 'boolean',
        ];
    }

    /**
     * Get the user that owns this customer.
     */
    public function user(): BelongsTo
    {
        $userModel = config('payment-gateway.billable_model', 'App\\Models\\User');

        return $this->belongsTo($userModel);
    }

    /**
     * Get all transactions for this customer.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'payment_customer_id');
    }

    /**
     * Get all subscriptions for this customer.
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'payment_customer_id');
    }

    /**
     * Get all payment methods for this customer.
     */
    public function paymentMethods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class, 'payment_customer_id');
    }

    /**
     * Get provider ID for a specific driver.
     */
    public function getProviderId(string $driver): ?string
    {
        return match ($driver) {
            'stripe' => $this->stripe_id,
            'paypal' => $this->paypal_id,
            'crypto' => $this->crypto_id,
            default => null,
        };
    }

    /**
     * Set provider ID for a specific driver.
     */
    public function setProviderId(string $driver, string $id): void
    {
        match ($driver) {
            'stripe' => $this->stripe_id = $id,
            'paypal' => $this->paypal_id = $id,
            'crypto' => $this->crypto_id = $id,
            default => null,
        };

        $this->save();
    }

    /**
     * Get billing address as DTO.
     */
    public function getBillingAddressDto(): ?Address
    {
        if ($this->billing_address === null) {
            return null;
        }

        return Address::fromArray($this->billing_address);
    }

    /**
     * Get shipping address as DTO.
     */
    public function getShippingAddressDto(): ?Address
    {
        if ($this->shipping_address === null) {
            return null;
        }

        return Address::fromArray($this->shipping_address);
    }

    /**
     * Get invoice address as DTO (falls back to billing).
     */
    public function getInvoiceAddressDto(): ?Address
    {
        $address = $this->invoice_address ?? $this->billing_address;

        if ($address === null) {
            return null;
        }

        return Address::fromArray($address);
    }

    /**
     * Get company as DTO.
     */
    public function getCompanyDto(): ?Company
    {
        if ($this->company === null) {
            return null;
        }

        return Company::fromArray($this->company);
    }

    /**
     * Convert to Customer DTO.
     */
    public function toDto(): Customer
    {
        return new Customer(
            id: $this->stripe_id ?? $this->paypal_id ?? (string) $this->id,
            email: $this->email,
            name: $this->name,
            phone: $this->phone,
            company: $this->getCompanyDto(),
            billingAddress: $this->getBillingAddressDto(),
            shippingAddress: $this->getShippingAddressDto(),
            invoiceAddress: $this->getInvoiceAddressDto(),
            taxId: $this->tax_id,
            vatNumber: $this->vat_number,
            preferredLocale: $this->preferred_locale,
            metadata: $this->metadata ?? [],
        );
    }

    /**
     * Create from Customer DTO.
     */
    public static function fromDto(Customer $customer, ?int $userId = null): static
    {
        return new self([
            'user_id' => $userId,
            'email' => $customer->email,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'company' => $customer->company?->toArray(),
            'billing_address' => $customer->billingAddress?->toArray(),
            'shipping_address' => $customer->shippingAddress?->toArray(),
            'invoice_address' => $customer->invoiceAddress?->toArray(),
            'tax_id' => $customer->taxId,
            'vat_number' => $customer->vatNumber,
            'preferred_locale' => $customer->preferredLocale,
            'is_business' => $customer->isBusiness(),
            'metadata' => $customer->metadata,
        ]);
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): PaymentCustomerFactory
    {
        return PaymentCustomerFactory::new();
    }
}

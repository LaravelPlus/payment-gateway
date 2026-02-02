<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\DTOs;

use DateTimeImmutable;

/**
 * Payment method data transfer object.
 */
final readonly class PaymentMethodData
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $driver,
        public ?string $cardBrand = null,
        public ?string $cardLastFour = null,
        public ?int $cardExpMonth = null,
        public ?int $cardExpYear = null,
        public ?string $bankName = null,
        public ?string $bankLastFour = null,
        public ?string $paypalEmail = null,
        public ?string $cryptoCurrency = null,
        public ?string $cryptoAddress = null,
        public ?Address $billingAddress = null,
        public bool $isDefault = false,
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
            type: $data['type'],
            driver: $data['driver'],
            cardBrand: $data['card_brand'] ?? null,
            cardLastFour: $data['card_last_four'] ?? null,
            cardExpMonth: $data['card_exp_month'] ?? null,
            cardExpYear: $data['card_exp_year'] ?? null,
            bankName: $data['bank_name'] ?? null,
            bankLastFour: $data['bank_last_four'] ?? null,
            paypalEmail: $data['paypal_email'] ?? null,
            cryptoCurrency: $data['crypto_currency'] ?? null,
            cryptoAddress: $data['crypto_address'] ?? null,
            billingAddress: isset($data['billing_address']) ? Address::fromArray($data['billing_address']) : null,
            isDefault: $data['is_default'] ?? false,
            raw: $data['raw'] ?? [],
        );
    }

    /**
     * Check if this is a card.
     */
    public function isCard(): bool
    {
        return $this->type === 'card';
    }

    /**
     * Check if card is expired.
     */
    public function isExpired(): bool
    {
        if (!$this->isCard() || $this->cardExpYear === null || $this->cardExpMonth === null) {
            return false;
        }

        $now = new DateTimeImmutable();
        $expiry = DateTimeImmutable::createFromFormat('Y-m', "{$this->cardExpYear}-{$this->cardExpMonth}")->modify('last day of this month');

        return $expiry < $now;
    }

    /**
     * Get display name.
     */
    public function getDisplayName(): string
    {
        return match ($this->type) {
            'card' => ucfirst($this->cardBrand ?? 'Card')." •••• {$this->cardLastFour}",
            'bank_account' => ($this->bankName ?? 'Bank')." •••• {$this->bankLastFour}",
            'paypal' => "PayPal ({$this->paypalEmail})",
            'crypto' => mb_strtoupper($this->cryptoCurrency ?? 'Crypto').' Wallet',
            default => ucfirst($this->type),
        };
    }

    /**
     * Get expiry string for cards.
     */
    public function getExpiryString(): ?string
    {
        if (!$this->isCard() || $this->cardExpMonth === null || $this->cardExpYear === null) {
            return null;
        }

        return sprintf('%02d/%d', $this->cardExpMonth, $this->cardExpYear % 100);
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
            'type' => $this->type,
            'driver' => $this->driver,
            'card_brand' => $this->cardBrand,
            'card_last_four' => $this->cardLastFour,
            'card_exp_month' => $this->cardExpMonth,
            'card_exp_year' => $this->cardExpYear,
            'bank_name' => $this->bankName,
            'bank_last_four' => $this->bankLastFour,
            'paypal_email' => $this->paypalEmail,
            'crypto_currency' => $this->cryptoCurrency,
            'crypto_address' => $this->cryptoAddress,
            'billing_address' => $this->billingAddress?->toArray(),
            'is_default' => $this->isDefault,
            'display_name' => $this->getDisplayName(),
            'expiry' => $this->getExpiryString(),
        ], fn ($v) => $v !== null);
    }
}

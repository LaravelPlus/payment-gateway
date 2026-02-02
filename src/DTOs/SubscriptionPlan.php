<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\DTOs;

use NumberFormatter;

/**
 * Subscription plan data transfer object.
 *
 * All monetary amounts are in cents (smallest currency unit).
 */
final readonly class SubscriptionPlan
{
    /**
     * @param  array<string, mixed>  $features
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $id,
        public string $productId,
        public string $name,
        public int $amount,
        public string $currency,
        public string $interval,
        public int $intervalCount,
        public string $driver,
        public ?string $description = null,
        public ?int $trialDays = null,
        public array $features = [],
        public bool $isActive = true,
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
            productId: $data['product_id'],
            name: $data['name'],
            amount: (int) $data['amount'],
            currency: mb_strtoupper($data['currency']),
            interval: $data['interval'],
            intervalCount: (int) ($data['interval_count'] ?? 1),
            driver: $data['driver'],
            description: $data['description'] ?? null,
            trialDays: $data['trial_days'] ?? null,
            features: $data['features'] ?? [],
            isActive: $data['is_active'] ?? true,
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
     * Get formatted amount.
     */
    public function getFormattedAmount(): string
    {
        $formatter = new NumberFormatter('en', NumberFormatter::CURRENCY);

        return $formatter->formatCurrency($this->getAmountDecimal(), $this->currency);
    }

    /**
     * Get billing description.
     */
    public function getBillingDescription(): string
    {
        $amount = $this->getFormattedAmount();
        $interval = $this->intervalCount > 1
            ? "{$this->intervalCount} {$this->interval}s"
            : $this->interval;

        return "{$amount} / {$interval}";
    }

    /**
     * Get interval label.
     */
    public function getIntervalLabel(): string
    {
        if ($this->intervalCount === 1) {
            return match ($this->interval) {
                'day' => 'Daily',
                'week' => 'Weekly',
                'month' => 'Monthly',
                'year' => 'Yearly',
                default => ucfirst($this->interval),
            };
        }

        return "Every {$this->intervalCount} {$this->interval}s";
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
            'product_id' => $this->productId,
            'name' => $this->name,
            'amount' => $this->amount,
            'amount_decimal' => $this->getAmountDecimal(),
            'currency' => $this->currency,
            'interval' => $this->interval,
            'interval_count' => $this->intervalCount,
            'interval_label' => $this->getIntervalLabel(),
            'billing_description' => $this->getBillingDescription(),
            'driver' => $this->driver,
            'description' => $this->description,
            'trial_days' => $this->trialDays,
            'features' => $this->features,
            'is_active' => $this->isActive,
            'metadata' => $this->metadata,
        ];
    }
}

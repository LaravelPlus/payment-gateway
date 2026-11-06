<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\DTOs;

/**
 * One recurring Price on a subscription, e.g. the base plan or a per-seat item.
 *
 * Amounts are in the smallest currency unit.
 */
final readonly class SubscriptionItem
{
    public function __construct(
        public string $id,
        public string $priceId,
        public int $quantity,
        public int $unitAmount,
        public string $currency,
        public string $interval,
        public int $intervalCount = 1,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            priceId: $data['price_id'],
            quantity: (int) $data['quantity'],
            unitAmount: (int) $data['unit_amount'],
            currency: mb_strtoupper($data['currency']),
            interval: $data['interval'],
            intervalCount: (int) ($data['interval_count'] ?? 1),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'price_id' => $this->priceId,
            'quantity' => $this->quantity,
            'unit_amount' => $this->unitAmount,
            'currency' => $this->currency,
            'interval' => $this->interval,
            'interval_count' => $this->intervalCount,
        ];
    }
}

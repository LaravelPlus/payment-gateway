<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\DTOs;

use Nejcc\PaymentGateway\Enums\PaymentStatus;
use NumberFormatter;

/**
 * Payment result data transfer object.
 *
 * All monetary amounts are in cents (smallest currency unit).
 * Example: $10.00 = 1000 cents
 */
final readonly class PaymentResult
{
    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $transactionId,
        public PaymentStatus $status,
        public int $amount,
        public string $currency,
        public string $driver,
        public ?string $paymentMethodId = null,
        public ?string $customerId = null,
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
        public ?string $receiptUrl = null,
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
            transactionId: $data['transaction_id'],
            status: $data['status'] instanceof PaymentStatus
                ? $data['status']
                : PaymentStatus::from($data['status']),
            amount: (int) $data['amount'],
            currency: mb_strtoupper($data['currency']),
            driver: $data['driver'],
            paymentMethodId: $data['payment_method_id'] ?? null,
            customerId: $data['customer_id'] ?? null,
            failureCode: $data['failure_code'] ?? null,
            failureMessage: $data['failure_message'] ?? null,
            receiptUrl: $data['receipt_url'] ?? null,
            metadata: $data['metadata'] ?? [],
            raw: $data['raw'] ?? [],
        );
    }

    /**
     * Check if payment was successful.
     */
    public function isSuccessful(): bool
    {
        return $this->status->isSuccessful();
    }

    /**
     * Check if payment is pending.
     */
    public function isPending(): bool
    {
        return $this->status->isPending();
    }

    /**
     * Check if payment failed.
     */
    public function isFailed(): bool
    {
        return $this->status->isFailed();
    }

    /**
     * Get amount in decimal format.
     */
    public function getAmountDecimal(): float
    {
        return $this->amount / 100;
    }

    /**
     * Get formatted amount with currency symbol.
     */
    public function getFormattedAmount(): string
    {
        $formatter = new NumberFormatter('en', NumberFormatter::CURRENCY);

        return $formatter->formatCurrency($this->getAmountDecimal(), $this->currency);
    }

    /**
     * Convert to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'transaction_id' => $this->transactionId,
            'status' => $this->status->value,
            'amount' => $this->amount,
            'amount_decimal' => $this->getAmountDecimal(),
            'currency' => $this->currency,
            'driver' => $this->driver,
            'payment_method_id' => $this->paymentMethodId,
            'customer_id' => $this->customerId,
            'failure_code' => $this->failureCode,
            'failure_message' => $this->failureMessage,
            'receipt_url' => $this->receiptUrl,
            'metadata' => $this->metadata,
        ];
    }
}

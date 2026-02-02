<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Contracts;

use Nejcc\PaymentGateway\DTOs\Refund;

interface SupportsRefunds
{
    /**
     * Create a full refund for a transaction.
     */
    public function refund(string $transactionId, ?string $reason = null): Refund;

    /**
     * Create a partial refund for a transaction.
     */
    public function partialRefund(string $transactionId, int $amount, ?string $reason = null): Refund;

    /**
     * Get refund details.
     */
    public function getRefund(string $refundId): ?Refund;

    /**
     * Get all refunds for a transaction.
     *
     * @return array<Refund>
     */
    public function getRefundsForTransaction(string $transactionId): array;
}

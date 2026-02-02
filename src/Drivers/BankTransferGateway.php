<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Drivers;

use DateTimeInterface;
use Illuminate\Support\Str;
use Nejcc\PaymentGateway\DTOs\Customer;
use Nejcc\PaymentGateway\DTOs\PaymentIntent;
use Nejcc\PaymentGateway\DTOs\PaymentResult;
use Nejcc\PaymentGateway\Enums\PaymentStatus;

/**
 * Bank Transfer Payment Gateway Driver.
 *
 * This driver creates pending payments with bank transfer instructions.
 * Payments must be manually confirmed when the transfer is received.
 * All amounts are in cents.
 */
final class BankTransferGateway extends AbstractPaymentGateway
{
    public function getName(): string
    {
        return 'bank_transfer';
    }

    public function getDisplayName(): string
    {
        return 'Bank Transfer';
    }

    public function isAvailable(): bool
    {
        return !empty($this->getConfig('iban')) || !empty($this->getConfig('account_number'));
    }

    /**
     * @return array<string>
     */
    public function getSupportedCurrencies(): array
    {
        return ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'CHF', 'PLN', 'CZK', 'HUF', 'RON', 'BGN', 'HRK', 'NOK', 'SEK', 'DKK'];
    }

    /**
     * Get bank details for display.
     *
     * @return array<string, mixed>
     */
    public function getBankDetails(): array
    {
        return [
            'account_name' => $this->getConfig('account_name'),
            'account_number' => $this->getConfig('account_number'),
            'bank_name' => $this->getConfig('bank_name'),
            'swift_code' => $this->getConfig('swift_code'),
            'iban' => $this->getConfig('iban'),
            'instructions' => $this->getConfig('instructions'),
        ];
    }

    /**
     * Generate a unique payment reference.
     */
    protected function generateReference(): string
    {
        return 'PAY-'.mb_strtoupper(Str::random(8));
    }

    /**
     * Get expiry date for the transfer.
     */
    protected function getExpiryDate(): DateTimeInterface
    {
        $days = (int) $this->getConfig('expiry_days', 7);

        return now()->addDays($days);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function createPaymentIntent(
        int $amount,
        string $currency,
        ?Customer $customer = null,
        array $metadata = []
    ): PaymentIntent {
        $intentId = 'bt_'.Str::random(24);
        $reference = $this->generateReference();
        $expiresAt = $this->getExpiryDate();

        $bankDetails = $this->getBankDetails();

        $this->log('info', 'Bank transfer intent created', [
            'intent_id' => $intentId,
            'reference' => $reference,
            'amount' => $amount,
        ]);

        return new PaymentIntent(
            id: $intentId,
            clientSecret: $reference, // Use reference as client secret for verification
            status: PaymentStatus::Pending,
            amount: $amount,
            currency: mb_strtoupper($currency),
            driver: $this->getName(),
            customerId: $customer?->id,
            expiresAt: $expiresAt,
            metadata: array_merge($metadata, [
                'reference' => $reference,
                'bank_details' => $bankDetails,
                'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            ]),
        );
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function charge(
        int $amount,
        string $currency,
        string $paymentMethodId, // Not used for bank transfers
        array $options = []
    ): PaymentResult {
        $transactionId = 'bt_txn_'.Str::random(24);
        $reference = $this->generateReference();
        $expiresAt = $this->getExpiryDate();

        $bankDetails = $this->getBankDetails();

        $this->log('info', 'Bank transfer payment created', [
            'transaction_id' => $transactionId,
            'reference' => $reference,
            'amount' => $amount,
        ]);

        return new PaymentResult(
            transactionId: $transactionId,
            status: PaymentStatus::Pending,
            amount: $amount,
            currency: mb_strtoupper($currency),
            driver: $this->getName(),
            customerId: $options['customer_id'] ?? null,
            metadata: array_merge($options['metadata'] ?? [], [
                'reference' => $reference,
                'bank_details' => $bankDetails,
                'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
                'instructions' => "Please transfer {$this->formatAmount($amount, $currency)} to the following account with reference: {$reference}",
            ]),
        );
    }

    public function getPayment(string $transactionId): ?PaymentResult
    {
        // In a real implementation, you would fetch from database
        return null;
    }

    public function cancel(string $transactionId): bool
    {
        $this->log('info', 'Bank transfer payment canceled', ['transaction_id' => $transactionId]);

        return true;
    }

    /**
     * Confirm payment when bank transfer is received.
     * Call this when you verify the transfer has been received.
     */
    public function confirmTransfer(string $transactionId, string $reference, int $receivedAmount): PaymentResult
    {
        $this->log('info', 'Bank transfer confirmed', [
            'transaction_id' => $transactionId,
            'reference' => $reference,
            'received_amount' => $receivedAmount,
        ]);

        return new PaymentResult(
            transactionId: $transactionId,
            status: PaymentStatus::Succeeded,
            amount: $receivedAmount,
            currency: $this->currency,
            driver: $this->getName(),
            metadata: [
                'confirmed_at' => now()->toIso8601String(),
                'reference' => $reference,
                'received_amount' => $receivedAmount,
            ],
        );
    }

    /**
     * Mark transfer as expired.
     */
    public function markExpired(string $transactionId): PaymentResult
    {
        $this->log('info', 'Bank transfer expired', ['transaction_id' => $transactionId]);

        return new PaymentResult(
            transactionId: $transactionId,
            status: PaymentStatus::Expired,
            amount: 0,
            currency: $this->currency,
            driver: $this->getName(),
            metadata: [
                'expired_at' => now()->toIso8601String(),
            ],
        );
    }
}

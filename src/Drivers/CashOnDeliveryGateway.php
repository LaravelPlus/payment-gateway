<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Drivers;

use Illuminate\Support\Str;
use Nejcc\PaymentGateway\DTOs\Customer;
use Nejcc\PaymentGateway\DTOs\PaymentIntent;
use Nejcc\PaymentGateway\DTOs\PaymentResult;
use Nejcc\PaymentGateway\Enums\PaymentStatus;

/**
 * Cash on Delivery Payment Gateway Driver.
 *
 * This driver creates pending payments that are confirmed when delivery is made.
 * All amounts are in cents.
 */
final class CashOnDeliveryGateway extends AbstractPaymentGateway
{
    public function getName(): string
    {
        return 'cash_on_delivery';
    }

    public function getDisplayName(): string
    {
        return 'Cash on Delivery';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * @return array<string>
     */
    public function getSupportedCurrencies(): array
    {
        return ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'CHF', 'PLN', 'CZK', 'HUF', 'RON'];
    }

    /**
     * Check if COD is available for amount.
     */
    public function isAvailableForAmount(int $amount): bool
    {
        $maxAmount = (int) $this->getConfig('max_amount', 50000); // Default 500.00 in cents

        return $amount <= $maxAmount;
    }

    /**
     * Check if COD is available for country.
     */
    public function isAvailableForCountry(string $countryCode): bool
    {
        $enabledCountries = $this->getConfig('enabled_countries', []);

        if (empty($enabledCountries)) {
            return true;
        }

        return in_array(mb_strtoupper($countryCode), $enabledCountries);
    }

    /**
     * Get COD fee in cents.
     */
    public function getFee(int $orderAmount): int
    {
        $fee = $this->getConfig('fee', 0);
        $feeType = $this->getConfig('fee_type', 'fixed');

        if ($feeType === 'percentage') {
            return (int) round($orderAmount * ($fee / 100));
        }

        return (int) ($fee * 100); // Convert to cents if stored as decimal
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
        $fee = $this->getFee($amount);
        $totalAmount = $amount + $fee;

        $intentId = 'cod_'.Str::random(24);

        $this->log('info', 'COD payment intent created', [
            'intent_id' => $intentId,
            'amount' => $amount,
            'fee' => $fee,
        ]);

        return new PaymentIntent(
            id: $intentId,
            clientSecret: $intentId, // COD doesn't need a client secret
            status: PaymentStatus::Pending,
            amount: $totalAmount,
            currency: mb_strtoupper($currency),
            driver: $this->getName(),
            customerId: $customer?->id,
            metadata: array_merge($metadata, [
                'order_amount' => $amount,
                'cod_fee' => $fee,
            ]),
        );
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function charge(
        int $amount,
        string $currency,
        string $paymentMethodId,
        array $options = []
    ): PaymentResult {
        // For COD, "charging" means creating a pending payment
        // The actual payment happens on delivery

        $transactionId = 'cod_txn_'.Str::random(24);
        $fee = $this->getFee($amount);

        $this->log('info', 'COD payment created (pending delivery)', [
            'transaction_id' => $transactionId,
            'amount' => $amount,
        ]);

        return new PaymentResult(
            transactionId: $transactionId,
            status: PaymentStatus::Pending,
            amount: $amount + $fee,
            currency: mb_strtoupper($currency),
            driver: $this->getName(),
            customerId: $options['customer_id'] ?? null,
            metadata: array_merge($options['metadata'] ?? [], [
                'order_amount' => $amount,
                'cod_fee' => $fee,
                'instructions' => 'Payment will be collected upon delivery.',
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
        $this->log('info', 'COD payment canceled', ['transaction_id' => $transactionId]);

        return true;
    }

    /**
     * Confirm delivery and mark payment as succeeded.
     * Call this when the delivery is made and cash is collected.
     */
    public function confirmDelivery(string $transactionId, int $collectedAmount): PaymentResult
    {
        $this->log('info', 'COD delivery confirmed', [
            'transaction_id' => $transactionId,
            'collected_amount' => $collectedAmount,
        ]);

        return new PaymentResult(
            transactionId: $transactionId,
            status: PaymentStatus::Succeeded,
            amount: $collectedAmount,
            currency: $this->currency,
            driver: $this->getName(),
            metadata: [
                'confirmed_at' => now()->toIso8601String(),
                'collected_amount' => $collectedAmount,
            ],
        );
    }
}

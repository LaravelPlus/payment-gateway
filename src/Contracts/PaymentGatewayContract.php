<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Contracts;

use Nejcc\PaymentGateway\DTOs\Customer;
use Nejcc\PaymentGateway\DTOs\PaymentIntent;
use Nejcc\PaymentGateway\DTOs\PaymentResult;

interface PaymentGatewayContract
{
    /**
     * Get the driver name.
     */
    public function getName(): string;

    /**
     * Get the display name for the gateway.
     */
    public function getDisplayName(): string;

    /**
     * Check if the gateway is available/configured.
     */
    public function isAvailable(): bool;

    /**
     * Get supported currencies.
     *
     * @return array<string>
     */
    public function getSupportedCurrencies(): array;

    /**
     * Check if a currency is supported.
     */
    public function supportsCurrency(string $currency): bool;

    /**
     * Create a payment intent/session.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function createPaymentIntent(
        int $amount,
        string $currency,
        ?Customer $customer = null,
        array $metadata = []
    ): PaymentIntent;

    /**
     * Charge/capture a payment.
     *
     * @param  array<string, mixed>  $options
     */
    public function charge(
        int $amount,
        string $currency,
        string $paymentMethodId,
        array $options = []
    ): PaymentResult;

    /**
     * Get payment details by transaction ID.
     */
    public function getPayment(string $transactionId): ?PaymentResult;

    /**
     * Cancel a payment.
     */
    public function cancel(string $transactionId): bool;
}

<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Drivers;

use Illuminate\Support\Facades\Log;
use Nejcc\PaymentGateway\Contracts\PaymentGatewayContract;
use Nejcc\PaymentGateway\DTOs\Customer;
use Nejcc\PaymentGateway\DTOs\PaymentIntent;
use Nejcc\PaymentGateway\DTOs\PaymentResult;
use Nejcc\PaymentGateway\Exceptions\PaymentException;
use NumberFormatter;
use Throwable;

/**
 * Abstract base class for payment gateways.
 *
 * All monetary amounts are in cents (smallest currency unit).
 */
abstract class AbstractPaymentGateway implements PaymentGatewayContract
{
    /**
     * @var array<string, mixed>
     */
    protected array $config;

    protected string $currency;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(array $config = [], ?string $currency = null)
    {
        $this->config = $config;
        $this->currency = $currency ?? config('payment-gateway.currency', 'EUR');
    }

    /**
     * Get configuration value.
     */
    protected function getConfig(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }

    /**
     * Get the current currency.
     */
    public function getCurrency(): string
    {
        return $this->currency;
    }

    /**
     * Set the currency.
     */
    public function setCurrency(string $currency): static
    {
        $this->currency = mb_strtoupper($currency);

        return $this;
    }

    /**
     * Check if a currency is supported.
     */
    public function supportsCurrency(string $currency): bool
    {
        return in_array(mb_strtoupper($currency), $this->getSupportedCurrencies());
    }

    /**
     * Log a payment operation.
     *
     * @param  array<string, mixed>  $context
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        if (!config('payment-gateway.logging.enabled', true)) {
            return;
        }

        $channel = config('payment-gateway.logging.channel', 'stack');
        $context['driver'] = $this->getName();

        Log::channel($channel)->log($level, "[PaymentGateway] {$message}", $context);
    }

    /**
     * Convert amount to cents if needed.
     */
    protected function ensureCents(int|float $amount): int
    {
        // If it's already an integer, assume it's in cents
        if (is_int($amount)) {
            return $amount;
        }

        // If it's a float, convert to cents
        return (int) round($amount * 100);
    }

    /**
     * Format amount for display.
     */
    protected function formatAmount(int $amountInCents, string $currency): string
    {
        $formatter = new NumberFormatter('en', NumberFormatter::CURRENCY);

        return $formatter->formatCurrency($amountInCents / 100, $currency);
    }

    /**
     * Create a payment intent/session.
     *
     * @param  array<string, mixed>  $metadata
     */
    abstract public function createPaymentIntent(
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
    abstract public function charge(
        int $amount,
        string $currency,
        string $paymentMethodId,
        array $options = []
    ): PaymentResult;

    /**
     * Get payment details by transaction ID.
     */
    abstract public function getPayment(string $transactionId): ?PaymentResult;

    /**
     * Cancel a payment.
     */
    abstract public function cancel(string $transactionId): bool;

    /**
     * Throw a payment exception.
     *
     * @throws PaymentException
     */
    protected function throwException(string $message, ?string $code = null, ?Throwable $previous = null): never
    {
        $this->log('error', $message, [
            'code' => $code,
            'previous' => $previous?->getMessage(),
        ]);

        throw new PaymentException($message, $code, $previous);
    }
}

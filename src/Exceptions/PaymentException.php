<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Exceptions;

use Exception;
use Nejcc\PaymentGateway\Enums\PaymentStatus;
use Throwable;

/**
 * Payment Gateway Exception.
 *
 * Thrown when payment operations fail.
 */
final class PaymentException extends Exception
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message = '',
        public readonly ?string $transactionId = null,
        public readonly ?string $driver = null,
        public readonly ?PaymentStatus $status = null,
        public readonly array $context = [],
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Create a payment failed exception.
     *
     * @param  array<string, mixed>  $context
     */
    public static function paymentFailed(
        string $message,
        ?string $transactionId = null,
        ?string $driver = null,
        array $context = [],
        ?Throwable $previous = null
    ): self {
        return new self(
            message: $message,
            transactionId: $transactionId,
            driver: $driver,
            status: PaymentStatus::Failed,
            context: $context,
            previous: $previous,
        );
    }

    /**
     * Create a gateway unavailable exception.
     */
    public static function gatewayUnavailable(string $driver): self
    {
        return new self(
            message: "Payment gateway '{$driver}' is not available or not configured.",
            driver: $driver,
        );
    }

    /**
     * Create an invalid configuration exception.
     */
    public static function invalidConfiguration(string $driver, string $key): self
    {
        return new self(
            message: "Missing or invalid configuration '{$key}' for payment gateway '{$driver}'.",
            driver: $driver,
        );
    }

    /**
     * Create an unsupported currency exception.
     */
    public static function unsupportedCurrency(string $currency, string $driver): self
    {
        return new self(
            message: "Currency '{$currency}' is not supported by payment gateway '{$driver}'.",
            driver: $driver,
            context: ['currency' => $currency],
        );
    }

    /**
     * Create an invalid amount exception.
     */
    public static function invalidAmount(int $amount): self
    {
        return new self(
            message: "Invalid payment amount: {$amount}. Amount must be a positive integer in cents.",
            context: ['amount' => $amount],
        );
    }

    /**
     * Create a webhook verification failed exception.
     */
    public static function webhookVerificationFailed(string $driver): self
    {
        return new self(
            message: "Webhook signature verification failed for gateway '{$driver}'.",
            driver: $driver,
        );
    }

    /**
     * Create a subscription exception.
     */
    public static function subscriptionFailed(string $message, ?string $subscriptionId = null, ?string $driver = null): self
    {
        return new self(
            message: $message,
            transactionId: $subscriptionId,
            driver: $driver,
            context: ['subscription_id' => $subscriptionId],
        );
    }

    /**
     * Create a refund exception.
     */
    public static function refundFailed(string $message, ?string $transactionId = null, ?string $driver = null): self
    {
        return new self(
            message: $message,
            transactionId: $transactionId,
            driver: $driver,
        );
    }

    /**
     * Create a customer exception.
     */
    public static function customerFailed(string $message, ?string $customerId = null, ?string $driver = null): self
    {
        return new self(
            message: $message,
            transactionId: $customerId,
            driver: $driver,
            context: ['customer_id' => $customerId],
        );
    }

    /**
     * Get exception context for logging.
     *
     * @return array<string, mixed>
     */
    public function getLogContext(): array
    {
        return array_filter([
            'message' => $this->getMessage(),
            'transaction_id' => $this->transactionId,
            'driver' => $this->driver,
            'status' => $this->status?->value,
            'context' => $this->context,
            'previous' => $this->getPrevious()?->getMessage(),
        ]);
    }
}

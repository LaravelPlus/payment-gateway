<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\DTOs;

/**
 * Hosted checkout session data transfer object.
 */
final readonly class CheckoutSession
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $id,
        public ?string $url,
        public string $status,
        public string $driver,
        public ?string $customerId = null,
        public ?string $subscriptionId = null,
        public array $raw = [],
    ) {}
}

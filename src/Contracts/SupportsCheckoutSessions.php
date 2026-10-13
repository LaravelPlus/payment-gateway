<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Contracts;

use LaravelPlus\PaymentGateway\DTOs\CheckoutSession;
use LaravelPlus\PaymentGateway\DTOs\Customer;

interface SupportsCheckoutSessions
{
    /**
     * Create a hosted checkout session for a subscription.
     *
     * @param  array<string, mixed>  $options
     */
    public function createCheckoutSession(
        Customer $customer,
        string $priceId,
        string $successUrl,
        string $cancelUrl,
        array $options = []
    ): CheckoutSession;

    /**
     * Create a billing portal session and return its URL.
     */
    public function createBillingPortalSession(string $customerId, string $returnUrl): string;
}

<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Contracts;

use Nejcc\PaymentGateway\DTOs\Customer;
use Nejcc\PaymentGateway\DTOs\PaymentMethodData;

interface SupportsCustomers
{
    /**
     * Create a customer on the payment provider.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function createCustomer(
        string $email,
        ?string $name = null,
        array $metadata = []
    ): Customer;

    /**
     * Get a customer by provider ID.
     */
    public function getCustomer(string $customerId): ?Customer;

    /**
     * Update a customer.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateCustomer(string $customerId, array $data): Customer;

    /**
     * Delete a customer.
     */
    public function deleteCustomer(string $customerId): bool;

    /**
     * Attach a payment method to a customer.
     */
    public function attachPaymentMethod(string $customerId, string $paymentMethodId): PaymentMethodData;

    /**
     * Detach a payment method from a customer.
     */
    public function detachPaymentMethod(string $paymentMethodId): bool;

    /**
     * Get customer's payment methods.
     *
     * @return array<PaymentMethodData>
     */
    public function getPaymentMethods(string $customerId): array;

    /**
     * Set default payment method for customer.
     */
    public function setDefaultPaymentMethod(string $customerId, string $paymentMethodId): bool;
}

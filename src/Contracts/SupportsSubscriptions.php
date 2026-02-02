<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Contracts;

use Nejcc\PaymentGateway\DTOs\Customer;
use Nejcc\PaymentGateway\DTOs\Subscription;
use Nejcc\PaymentGateway\DTOs\SubscriptionPlan;

interface SupportsSubscriptions
{
    /**
     * Create a subscription plan/product.
     *
     * @param  array<string, mixed>  $options
     */
    public function createPlan(
        string $name,
        int $amount,
        string $currency,
        string $interval,
        array $options = []
    ): SubscriptionPlan;

    /**
     * Get a subscription plan by ID.
     */
    public function getPlan(string $planId): ?SubscriptionPlan;

    /**
     * Create a subscription for a customer.
     *
     * @param  array<string, mixed>  $options
     */
    public function createSubscription(
        Customer $customer,
        string $planId,
        string $paymentMethodId,
        array $options = []
    ): Subscription;

    /**
     * Get a subscription by ID.
     */
    public function getSubscription(string $subscriptionId): ?Subscription;

    /**
     * Cancel a subscription.
     */
    public function cancelSubscription(string $subscriptionId, bool $immediately = false): bool;

    /**
     * Pause a subscription.
     */
    public function pauseSubscription(string $subscriptionId): bool;

    /**
     * Resume a paused subscription.
     */
    public function resumeSubscription(string $subscriptionId): bool;

    /**
     * Update subscription plan.
     */
    public function updateSubscription(string $subscriptionId, string $newPlanId): Subscription;
}

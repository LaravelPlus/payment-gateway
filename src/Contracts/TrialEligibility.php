<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Contracts;

use LaravelPlus\PaymentGateway\DTOs\Customer;

/**
 * Host-app policy for who may start a free trial.
 *
 * Bind an implementation in the container and every trial request through the
 * gateway is checked before it reaches the provider. The Customer is the billing
 * owner (an organization, a user, whatever the app attached it to), so rules
 * like "one trial per company" belong here. Return false to refuse the trial.
 */
interface TrialEligibility
{
    /**
     * @param  array<string, mixed>  $options  the options passed to the gateway call
     */
    public function allowsTrial(Customer $customer, string $priceId, array $options): bool;
}

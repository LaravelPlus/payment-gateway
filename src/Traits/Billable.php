<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Traits;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Nejcc\PaymentGateway\DTOs\Customer;
use Nejcc\PaymentGateway\Facades\Payment;
use RuntimeException;

/**
 * Billable trait for User model.
 *
 * Add this trait to your User model to enable payment functionality.
 *
 * Required columns on users table:
 * - stripe_id (nullable string)
 * - paypal_id (nullable string)
 * - payment_customer_id (nullable string) - default provider customer ID
 */
trait Billable
{
    /**
     * Get all payment customers for this user.
     */
    public function paymentCustomers(): HasMany
    {
        $model = config('payment-gateway.models.payment_customer');

        return $this->hasMany($model, 'user_id');
    }

    /**
     * Get the primary payment customer.
     */
    public function paymentCustomer(): HasOne
    {
        $model = config('payment-gateway.models.payment_customer');

        return $this->hasOne($model, 'user_id')->where('is_primary', true);
    }

    /**
     * Get all transactions for this user.
     */
    public function transactions(): HasMany
    {
        $model = config('payment-gateway.models.transaction');

        return $this->hasMany($model, 'user_id');
    }

    /**
     * Get all subscriptions for this user.
     */
    public function subscriptions(): HasMany
    {
        $model = config('payment-gateway.models.subscription');

        return $this->hasMany($model, 'user_id');
    }

    /**
     * Get the customer ID for a specific provider.
     */
    public function getPaymentCustomerId(?string $driver = null): ?string
    {
        $driver = $driver ?? config('payment-gateway.default');

        return match ($driver) {
            'stripe' => $this->stripe_id,
            'paypal' => $this->paypal_id,
            default => $this->payment_customer_id,
        };
    }

    /**
     * Set the customer ID for a specific provider.
     */
    public function setPaymentCustomerId(string $customerId, ?string $driver = null): void
    {
        $driver = $driver ?? config('payment-gateway.default');

        match ($driver) {
            'stripe' => $this->stripe_id = $customerId,
            'paypal' => $this->paypal_id = $customerId,
            default => $this->payment_customer_id = $customerId,
        };

        $this->save();
    }

    /**
     * Check if user has a customer ID for a provider.
     */
    public function hasPaymentCustomerId(?string $driver = null): bool
    {
        return $this->getPaymentCustomerId($driver) !== null;
    }

    /**
     * Create a customer on the payment provider.
     *
     * @param  array<string, mixed>  $options
     */
    public function createAsPaymentCustomer(?string $driver = null, array $options = []): Customer
    {
        $gateway = Payment::driver($driver);

        if (!($gateway instanceof \Nejcc\PaymentGateway\Contracts\SupportsCustomers)) {
            throw new RuntimeException("Driver {$driver} does not support customer management.");
        }

        $customer = $gateway->createCustomer(
            email: $this->email,
            name: $this->name,
            metadata: array_merge([
                'user_id' => $this->id,
            ], $options['metadata'] ?? [])
        );

        $this->setPaymentCustomerId($customer->id, $driver);

        return $customer;
    }

    /**
     * Get or create customer on payment provider.
     *
     * @param  array<string, mixed>  $options
     */
    public function getOrCreatePaymentCustomer(?string $driver = null, array $options = []): Customer
    {
        $customerId = $this->getPaymentCustomerId($driver);

        if ($customerId !== null) {
            $gateway = Payment::driver($driver);
            if ($gateway instanceof \Nejcc\PaymentGateway\Contracts\SupportsCustomers) {
                $customer = $gateway->getCustomer($customerId);
                if ($customer !== null) {
                    return $customer;
                }
            }
        }

        return $this->createAsPaymentCustomer($driver, $options);
    }

    /**
     * Convert to Customer DTO.
     */
    public function toPaymentCustomer(): Customer
    {
        return Customer::fromBillable($this);
    }

    /**
     * Charge the user.
     *
     * @param  int  $amount  Amount in cents
     * @param  array<string, mixed>  $options
     */
    public function charge(int $amount, string $paymentMethodId, array $options = []): \Nejcc\PaymentGateway\DTOs\PaymentResult
    {
        $driver = $options['driver'] ?? null;
        $currency = $options['currency'] ?? config('payment-gateway.currency');

        return Payment::driver($driver)->charge($amount, $currency, $paymentMethodId, array_merge([
            'customer_id' => $this->getPaymentCustomerId($driver),
            'metadata' => [
                'user_id' => $this->id,
            ],
        ], $options));
    }

    /**
     * Check if user has an active subscription.
     */
    public function subscribed(?string $plan = null): bool
    {
        $query = $this->subscriptions()->whereIn('status', ['active', 'trialing']);

        if ($plan !== null) {
            $query->where('plan_id', $plan);
        }

        return $query->exists();
    }

    /**
     * Check if user is on trial.
     */
    public function onTrial(?string $plan = null): bool
    {
        $query = $this->subscriptions()->where('status', 'trialing');

        if ($plan !== null) {
            $query->where('plan_id', $plan);
        }

        return $query->exists();
    }

    /**
     * Get the user's active subscription for a plan.
     */
    public function subscription(?string $plan = null): ?\Illuminate\Database\Eloquent\Model
    {
        $query = $this->subscriptions()->whereIn('status', ['active', 'trialing', 'past_due']);

        if ($plan !== null) {
            $query->where('plan_id', $plan);
        }

        return $query->first();
    }
}

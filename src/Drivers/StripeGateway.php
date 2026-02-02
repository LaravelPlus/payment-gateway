<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Drivers;

use DateTimeImmutable;
use Illuminate\Http\Request;
use Nejcc\PaymentGateway\Contracts\SupportsCustomers;
use Nejcc\PaymentGateway\Contracts\SupportsRefunds;
use Nejcc\PaymentGateway\Contracts\SupportsSubscriptions;
use Nejcc\PaymentGateway\Contracts\SupportsWebhooks;
use Nejcc\PaymentGateway\DTOs\Customer;
use Nejcc\PaymentGateway\DTOs\PaymentIntent;
use Nejcc\PaymentGateway\DTOs\PaymentMethodData;
use Nejcc\PaymentGateway\DTOs\PaymentResult;
use Nejcc\PaymentGateway\DTOs\Refund;
use Nejcc\PaymentGateway\DTOs\Subscription;
use Nejcc\PaymentGateway\DTOs\SubscriptionPlan;
use Nejcc\PaymentGateway\DTOs\WebhookPayload;
use Nejcc\PaymentGateway\Enums\PaymentStatus;
use Nejcc\PaymentGateway\Enums\SubscriptionStatus;
use Nejcc\PaymentGateway\Exceptions\PaymentException;

/**
 * Stripe Payment Gateway Driver.
 *
 * Requires: stripe/stripe-php package
 * All amounts are in cents.
 */
final class StripeGateway extends AbstractPaymentGateway implements SupportsCustomers, SupportsRefunds, SupportsSubscriptions, SupportsWebhooks
{
    private ?\Stripe\StripeClient $client = null;

    public function getName(): string
    {
        return 'stripe';
    }

    public function getDisplayName(): string
    {
        return 'Credit Card (Stripe)';
    }

    public function isAvailable(): bool
    {
        return class_exists(\Stripe\StripeClient::class)
            && !empty($this->getConfig('secret'));
    }

    /**
     * @return array<string>
     */
    public function getSupportedCurrencies(): array
    {
        return ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'JPY', 'CHF', 'NOK', 'SEK', 'DKK', 'PLN', 'CZK', 'HUF', 'RON', 'BGN', 'HRK'];
    }

    /**
     * Get the Stripe client.
     */
    protected function getClient(): \Stripe\StripeClient
    {
        if ($this->client === null) {
            if (!class_exists(\Stripe\StripeClient::class)) {
                throw new PaymentException('Stripe PHP SDK is not installed. Run: composer require stripe/stripe-php');
            }

            $this->client = new \Stripe\StripeClient([
                'api_key' => $this->getConfig('secret'),
                'stripe_version' => $this->getConfig('api_version', '2024-06-20'),
            ]);
        }

        return $this->client;
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
        try {
            $params = [
                'amount' => $amount,
                'currency' => mb_strtolower($currency),
                'metadata' => $metadata,
            ];

            if ($customer !== null && $customer->id !== null) {
                $params['customer'] = $customer->id;
            }

            $intent = $this->getClient()->paymentIntents->create($params);

            $this->log('info', 'Payment intent created', ['intent_id' => $intent->id, 'amount' => $amount]);

            return new PaymentIntent(
                id: $intent->id,
                clientSecret: $intent->client_secret,
                status: $this->mapStripeStatus($intent->status),
                amount: $intent->amount,
                currency: mb_strtoupper($intent->currency),
                driver: $this->getName(),
                customerId: $intent->customer,
                metadata: $intent->metadata?->toArray() ?? [],
                raw: $intent->toArray(),
            );
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $this->throwException("Failed to create payment intent: {$e->getMessage()}", $e->getStripeCode(), $e);
        }
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
        try {
            $params = [
                'amount' => $amount,
                'currency' => mb_strtolower($currency),
                'payment_method' => $paymentMethodId,
                'confirm' => true,
                'return_url' => $options['return_url'] ?? config('app.url').'/payment/callback',
                'metadata' => $options['metadata'] ?? [],
            ];

            if (!empty($options['customer_id'])) {
                $params['customer'] = $options['customer_id'];
            }

            if (!empty($options['description'])) {
                $params['description'] = $options['description'];
            }

            $intent = $this->getClient()->paymentIntents->create($params);

            $this->log('info', 'Charge completed', ['intent_id' => $intent->id, 'status' => $intent->status]);

            return new PaymentResult(
                transactionId: $intent->id,
                status: $this->mapStripeStatus($intent->status),
                amount: $intent->amount,
                currency: mb_strtoupper($intent->currency),
                driver: $this->getName(),
                paymentMethodId: $intent->payment_method,
                customerId: $intent->customer,
                receiptUrl: $intent->charges?->data[0]?->receipt_url,
                metadata: $intent->metadata?->toArray() ?? [],
                raw: $intent->toArray(),
            );
        } catch (\Stripe\Exception\CardException $e) {
            return new PaymentResult(
                transactionId: $e->getStripeParam() ?? 'unknown',
                status: PaymentStatus::Failed,
                amount: $amount,
                currency: mb_strtoupper($currency),
                driver: $this->getName(),
                failureCode: $e->getStripeCode(),
                failureMessage: $e->getMessage(),
            );
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $this->throwException("Charge failed: {$e->getMessage()}", $e->getStripeCode(), $e);
        }
    }

    public function getPayment(string $transactionId): ?PaymentResult
    {
        try {
            $intent = $this->getClient()->paymentIntents->retrieve($transactionId);

            return new PaymentResult(
                transactionId: $intent->id,
                status: $this->mapStripeStatus($intent->status),
                amount: $intent->amount,
                currency: mb_strtoupper($intent->currency),
                driver: $this->getName(),
                paymentMethodId: $intent->payment_method,
                customerId: $intent->customer,
                receiptUrl: $intent->charges?->data[0]?->receipt_url,
                metadata: $intent->metadata?->toArray() ?? [],
                raw: $intent->toArray(),
            );
        } catch (\Stripe\Exception\ApiErrorException) {
            return null;
        }
    }

    public function cancel(string $transactionId): bool
    {
        try {
            $this->getClient()->paymentIntents->cancel($transactionId);
            $this->log('info', 'Payment canceled', ['intent_id' => $transactionId]);

            return true;
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $this->log('error', 'Failed to cancel payment', ['intent_id' => $transactionId, 'error' => $e->getMessage()]);

            return false;
        }
    }

    // ========================================
    // SupportsCustomers
    // ========================================

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function createCustomer(string $email, ?string $name = null, array $metadata = []): Customer
    {
        try {
            $customer = $this->getClient()->customers->create([
                'email' => $email,
                'name' => $name,
                'metadata' => $metadata,
            ]);

            $this->log('info', 'Customer created', ['customer_id' => $customer->id]);

            return new Customer(
                id: $customer->id,
                email: $customer->email,
                name: $customer->name,
                metadata: $customer->metadata?->toArray() ?? [],
                raw: $customer->toArray(),
            );
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $this->throwException("Failed to create customer: {$e->getMessage()}", $e->getStripeCode(), $e);
        }
    }

    public function getCustomer(string $customerId): ?Customer
    {
        try {
            $customer = $this->getClient()->customers->retrieve($customerId);

            return new Customer(
                id: $customer->id,
                email: $customer->email,
                name: $customer->name,
                phone: $customer->phone,
                defaultPaymentMethodId: $customer->invoice_settings?->default_payment_method,
                metadata: $customer->metadata?->toArray() ?? [],
                raw: $customer->toArray(),
            );
        } catch (\Stripe\Exception\ApiErrorException) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateCustomer(string $customerId, array $data): Customer
    {
        try {
            $customer = $this->getClient()->customers->update($customerId, $data);

            return new Customer(
                id: $customer->id,
                email: $customer->email,
                name: $customer->name,
                metadata: $customer->metadata?->toArray() ?? [],
                raw: $customer->toArray(),
            );
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $this->throwException("Failed to update customer: {$e->getMessage()}", $e->getStripeCode(), $e);
        }
    }

    public function deleteCustomer(string $customerId): bool
    {
        try {
            $this->getClient()->customers->delete($customerId);

            return true;
        } catch (\Stripe\Exception\ApiErrorException) {
            return false;
        }
    }

    public function attachPaymentMethod(string $customerId, string $paymentMethodId): PaymentMethodData
    {
        try {
            $pm = $this->getClient()->paymentMethods->attach($paymentMethodId, ['customer' => $customerId]);

            return new PaymentMethodData(
                id: $pm->id,
                type: $pm->type,
                driver: $this->getName(),
                cardBrand: $pm->card?->brand,
                cardLastFour: $pm->card?->last4,
                cardExpMonth: $pm->card?->exp_month,
                cardExpYear: $pm->card?->exp_year,
            );
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $this->throwException("Failed to attach payment method: {$e->getMessage()}", $e->getStripeCode(), $e);
        }
    }

    public function detachPaymentMethod(string $paymentMethodId): bool
    {
        try {
            $this->getClient()->paymentMethods->detach($paymentMethodId);

            return true;
        } catch (\Stripe\Exception\ApiErrorException) {
            return false;
        }
    }

    /**
     * @return array<PaymentMethodData>
     */
    public function getPaymentMethods(string $customerId): array
    {
        try {
            $methods = $this->getClient()->paymentMethods->all([
                'customer' => $customerId,
                'type' => 'card',
            ]);

            return array_map(fn ($pm) => new PaymentMethodData(
                id: $pm->id,
                type: $pm->type,
                driver: $this->getName(),
                cardBrand: $pm->card?->brand,
                cardLastFour: $pm->card?->last4,
                cardExpMonth: $pm->card?->exp_month,
                cardExpYear: $pm->card?->exp_year,
            ), $methods->data);
        } catch (\Stripe\Exception\ApiErrorException) {
            return [];
        }
    }

    public function setDefaultPaymentMethod(string $customerId, string $paymentMethodId): bool
    {
        try {
            $this->getClient()->customers->update($customerId, [
                'invoice_settings' => ['default_payment_method' => $paymentMethodId],
            ]);

            return true;
        } catch (\Stripe\Exception\ApiErrorException) {
            return false;
        }
    }

    // ========================================
    // SupportsRefunds
    // ========================================

    public function refund(string $transactionId, ?string $reason = null): Refund
    {
        try {
            $refund = $this->getClient()->refunds->create([
                'payment_intent' => $transactionId,
                'reason' => $reason ?? 'requested_by_customer',
            ]);

            $this->log('info', 'Refund created', ['refund_id' => $refund->id, 'transaction_id' => $transactionId]);

            return new Refund(
                id: $refund->id,
                transactionId: $transactionId,
                status: $refund->status,
                amount: $refund->amount,
                currency: mb_strtoupper($refund->currency),
                driver: $this->getName(),
                reason: $reason,
                raw: $refund->toArray(),
            );
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $this->throwException("Failed to create refund: {$e->getMessage()}", $e->getStripeCode(), $e);
        }
    }

    public function partialRefund(string $transactionId, int $amount, ?string $reason = null): Refund
    {
        try {
            $refund = $this->getClient()->refunds->create([
                'payment_intent' => $transactionId,
                'amount' => $amount,
                'reason' => $reason ?? 'requested_by_customer',
            ]);

            $this->log('info', 'Partial refund created', ['refund_id' => $refund->id, 'amount' => $amount]);

            return new Refund(
                id: $refund->id,
                transactionId: $transactionId,
                status: $refund->status,
                amount: $refund->amount,
                currency: mb_strtoupper($refund->currency),
                driver: $this->getName(),
                reason: $reason,
                raw: $refund->toArray(),
            );
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $this->throwException("Failed to create partial refund: {$e->getMessage()}", $e->getStripeCode(), $e);
        }
    }

    public function getRefund(string $refundId): ?Refund
    {
        try {
            $refund = $this->getClient()->refunds->retrieve($refundId);

            return new Refund(
                id: $refund->id,
                transactionId: $refund->payment_intent,
                status: $refund->status,
                amount: $refund->amount,
                currency: mb_strtoupper($refund->currency),
                driver: $this->getName(),
                reason: $refund->reason,
                raw: $refund->toArray(),
            );
        } catch (\Stripe\Exception\ApiErrorException) {
            return null;
        }
    }

    /**
     * @return array<Refund>
     */
    public function getRefundsForTransaction(string $transactionId): array
    {
        try {
            $refunds = $this->getClient()->refunds->all(['payment_intent' => $transactionId]);

            return array_map(fn ($r) => new Refund(
                id: $r->id,
                transactionId: $transactionId,
                status: $r->status,
                amount: $r->amount,
                currency: mb_strtoupper($r->currency),
                driver: $this->getName(),
                reason: $r->reason,
                raw: $r->toArray(),
            ), $refunds->data);
        } catch (\Stripe\Exception\ApiErrorException) {
            return [];
        }
    }

    // ========================================
    // SupportsSubscriptions
    // ========================================

    /**
     * @param  array<string, mixed>  $options
     */
    public function createPlan(string $name, int $amount, string $currency, string $interval, array $options = []): SubscriptionPlan
    {
        try {
            $product = $this->getClient()->products->create([
                'name' => $name,
                'metadata' => $options['metadata'] ?? [],
            ]);

            $price = $this->getClient()->prices->create([
                'product' => $product->id,
                'unit_amount' => $amount,
                'currency' => mb_strtolower($currency),
                'recurring' => [
                    'interval' => $interval,
                    'interval_count' => $options['interval_count'] ?? 1,
                ],
            ]);

            return new SubscriptionPlan(
                id: $price->id,
                productId: $product->id,
                name: $name,
                amount: $amount,
                currency: mb_strtoupper($currency),
                interval: $interval,
                intervalCount: $options['interval_count'] ?? 1,
                driver: $this->getName(),
            );
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $this->throwException("Failed to create plan: {$e->getMessage()}", $e->getStripeCode(), $e);
        }
    }

    public function getPlan(string $planId): ?SubscriptionPlan
    {
        try {
            $price = $this->getClient()->prices->retrieve($planId, ['expand' => ['product']]);

            return new SubscriptionPlan(
                id: $price->id,
                productId: $price->product->id,
                name: $price->product->name,
                amount: $price->unit_amount,
                currency: mb_strtoupper($price->currency),
                interval: $price->recurring->interval,
                intervalCount: $price->recurring->interval_count,
                driver: $this->getName(),
            );
        } catch (\Stripe\Exception\ApiErrorException) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function createSubscription(Customer $customer, string $planId, string $paymentMethodId, array $options = []): Subscription
    {
        try {
            $sub = $this->getClient()->subscriptions->create([
                'customer' => $customer->id,
                'items' => [['price' => $planId]],
                'default_payment_method' => $paymentMethodId,
                'trial_period_days' => $options['trial_days'] ?? null,
                'metadata' => $options['metadata'] ?? [],
            ]);

            $price = $sub->items->data[0]->price;

            return new Subscription(
                id: $sub->id,
                customerId: $sub->customer,
                planId: $planId,
                status: $this->mapStripeSubscriptionStatus($sub->status),
                amount: $price->unit_amount,
                currency: mb_strtoupper($price->currency),
                interval: $price->recurring->interval,
                driver: $this->getName(),
                currentPeriodStart: DateTimeImmutable::createFromFormat('U', (string) $sub->current_period_start),
                currentPeriodEnd: DateTimeImmutable::createFromFormat('U', (string) $sub->current_period_end),
                trialStart: $sub->trial_start ? DateTimeImmutable::createFromFormat('U', (string) $sub->trial_start) : null,
                trialEnd: $sub->trial_end ? DateTimeImmutable::createFromFormat('U', (string) $sub->trial_end) : null,
                cancelAtPeriodEnd: $sub->cancel_at_period_end,
                raw: $sub->toArray(),
            );
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $this->throwException("Failed to create subscription: {$e->getMessage()}", $e->getStripeCode(), $e);
        }
    }

    public function getSubscription(string $subscriptionId): ?Subscription
    {
        try {
            $sub = $this->getClient()->subscriptions->retrieve($subscriptionId);
            $price = $sub->items->data[0]->price;

            return new Subscription(
                id: $sub->id,
                customerId: $sub->customer,
                planId: $price->id,
                status: $this->mapStripeSubscriptionStatus($sub->status),
                amount: $price->unit_amount,
                currency: mb_strtoupper($price->currency),
                interval: $price->recurring->interval,
                driver: $this->getName(),
                currentPeriodStart: DateTimeImmutable::createFromFormat('U', (string) $sub->current_period_start),
                currentPeriodEnd: DateTimeImmutable::createFromFormat('U', (string) $sub->current_period_end),
                canceledAt: $sub->canceled_at ? DateTimeImmutable::createFromFormat('U', (string) $sub->canceled_at) : null,
                cancelAtPeriodEnd: $sub->cancel_at_period_end,
                raw: $sub->toArray(),
            );
        } catch (\Stripe\Exception\ApiErrorException) {
            return null;
        }
    }

    public function cancelSubscription(string $subscriptionId, bool $immediately = false): bool
    {
        try {
            if ($immediately) {
                $this->getClient()->subscriptions->cancel($subscriptionId);
            } else {
                $this->getClient()->subscriptions->update($subscriptionId, ['cancel_at_period_end' => true]);
            }

            return true;
        } catch (\Stripe\Exception\ApiErrorException) {
            return false;
        }
    }

    public function pauseSubscription(string $subscriptionId): bool
    {
        try {
            $this->getClient()->subscriptions->update($subscriptionId, [
                'pause_collection' => ['behavior' => 'void'],
            ]);

            return true;
        } catch (\Stripe\Exception\ApiErrorException) {
            return false;
        }
    }

    public function resumeSubscription(string $subscriptionId): bool
    {
        try {
            $this->getClient()->subscriptions->update($subscriptionId, [
                'pause_collection' => '',
            ]);

            return true;
        } catch (\Stripe\Exception\ApiErrorException) {
            return false;
        }
    }

    public function updateSubscription(string $subscriptionId, string $newPlanId): Subscription
    {
        try {
            $sub = $this->getClient()->subscriptions->retrieve($subscriptionId);

            $this->getClient()->subscriptions->update($subscriptionId, [
                'items' => [
                    ['id' => $sub->items->data[0]->id, 'price' => $newPlanId],
                ],
            ]);

            return $this->getSubscription($subscriptionId);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $this->throwException("Failed to update subscription: {$e->getMessage()}", $e->getStripeCode(), $e);
        }
    }

    // ========================================
    // SupportsWebhooks
    // ========================================

    public function verifyWebhookSignature(Request $request): bool
    {
        $signature = $request->header('Stripe-Signature');
        $secret = $this->getWebhookSecret();

        if ($signature === null || $secret === null) {
            return false;
        }

        try {
            \Stripe\Webhook::constructEvent(
                $request->getContent(),
                $signature,
                $secret,
                $this->getConfig('webhook_tolerance', 300)
            );

            return true;
        } catch (\Stripe\Exception\SignatureVerificationException) {
            return false;
        }
    }

    public function parseWebhook(Request $request): WebhookPayload
    {
        $payload = json_decode($request->getContent(), true);

        return new WebhookPayload(
            id: $payload['id'],
            type: $payload['type'],
            driver: $this->getName(),
            data: $payload['data']['object'] ?? [],
            createdAt: DateTimeImmutable::createFromFormat('U', (string) $payload['created']),
            raw: $payload,
        );
    }

    public function getWebhookSecret(): ?string
    {
        return $this->getConfig('webhook_secret');
    }

    /**
     * @return array<string, mixed>
     */
    public function handleWebhook(WebhookPayload $payload): array
    {
        $this->log('info', 'Webhook received', ['type' => $payload->type, 'id' => $payload->id]);

        return [
            'handled' => true,
            'type' => $payload->type,
        ];
    }

    // ========================================
    // Helpers
    // ========================================

    protected function mapStripeStatus(string $status): PaymentStatus
    {
        return match ($status) {
            'succeeded' => PaymentStatus::Succeeded,
            'processing' => PaymentStatus::Processing,
            'requires_action', 'requires_confirmation' => PaymentStatus::RequiresAction,
            'requires_capture' => PaymentStatus::RequiresCapture,
            'requires_payment_method' => PaymentStatus::Pending,
            'canceled' => PaymentStatus::Canceled,
            default => PaymentStatus::Failed,
        };
    }

    protected function mapStripeSubscriptionStatus(string $status): SubscriptionStatus
    {
        return match ($status) {
            'active' => SubscriptionStatus::Active,
            'trialing' => SubscriptionStatus::Trialing,
            'past_due' => SubscriptionStatus::PastDue,
            'paused' => SubscriptionStatus::Paused,
            'canceled' => SubscriptionStatus::Canceled,
            'unpaid' => SubscriptionStatus::Unpaid,
            'incomplete' => SubscriptionStatus::Incomplete,
            'incomplete_expired' => SubscriptionStatus::IncompleteExpired,
            default => SubscriptionStatus::Incomplete,
        };
    }
}

<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Drivers;

use DateTimeImmutable;
use Illuminate\Http\Request;
use LaravelPlus\PaymentGateway\Contracts\SupportsCheckoutSessions;
use LaravelPlus\PaymentGateway\Contracts\SupportsCustomers;
use LaravelPlus\PaymentGateway\Contracts\SupportsRefunds;
use LaravelPlus\PaymentGateway\Contracts\SupportsSubscriptions;
use LaravelPlus\PaymentGateway\Contracts\SupportsWebhooks;
use LaravelPlus\PaymentGateway\Contracts\TrialEligibility;
use LaravelPlus\PaymentGateway\DTOs\CheckoutSession;
use LaravelPlus\PaymentGateway\DTOs\Customer;
use LaravelPlus\PaymentGateway\DTOs\PaymentIntent;
use LaravelPlus\PaymentGateway\DTOs\PaymentMethodData;
use LaravelPlus\PaymentGateway\DTOs\PaymentResult;
use LaravelPlus\PaymentGateway\DTOs\Refund;
use LaravelPlus\PaymentGateway\DTOs\Subscription;
use LaravelPlus\PaymentGateway\DTOs\SubscriptionItem;
use LaravelPlus\PaymentGateway\DTOs\SubscriptionPlan;
use LaravelPlus\PaymentGateway\DTOs\Trial;
use LaravelPlus\PaymentGateway\DTOs\WebhookPayload;
use LaravelPlus\PaymentGateway\Enums\PaymentStatus;
use LaravelPlus\PaymentGateway\Enums\SubscriptionStatus;
use LaravelPlus\PaymentGateway\Exceptions\PaymentException;

/**
 * Stripe Payment Gateway Driver.
 *
 * Requires: stripe/stripe-php package
 * All amounts are in cents.
 */
final class StripeGateway extends AbstractPaymentGateway implements SupportsCheckoutSessions, SupportsCustomers, SupportsRefunds, SupportsSubscriptions, SupportsWebhooks
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
            $refund = $this->getClient()->refunds->create($this->refundParams($transactionId, null, $reason));

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

    /**
     * Webhook-created transactions store the charge id (ch_), API-created ones the
     * payment intent id (pi_). Stripe's `reason` only accepts three enum values;
     * anything else (free text from the admin) goes to metadata.
     *
     * @return array<string, mixed>
     */
    private function refundParams(string $transactionId, ?int $amount, ?string $reason): array
    {
        $params = [str_starts_with($transactionId, 'ch_') || str_starts_with($transactionId, 'py_') ? 'charge' : 'payment_intent' => $transactionId];

        if ($amount !== null) {
            $params['amount'] = $amount;
        }

        if (in_array($reason, ['duplicate', 'fraudulent', 'requested_by_customer'], true)) {
            $params['reason'] = $reason;
        } else {
            $params['reason'] = 'requested_by_customer';
            if ($reason !== null && $reason !== '') {
                $params['metadata'] = ['note' => $reason];
            }
        }

        return $params;
    }

    public function partialRefund(string $transactionId, int $amount, ?string $reason = null): Refund
    {
        try {
            $refund = $this->getClient()->refunds->create($this->refundParams($transactionId, $amount, $reason));

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
                'items' => $this->lineItems($planId, $options),
                'default_payment_method' => $paymentMethodId,
                'metadata' => $options['metadata'] ?? [],
                ...$this->trialParams($customer, $planId, $options),
            ]);

            return $this->toSubscription($sub);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $this->throwException("Failed to create subscription: {$e->getMessage()}", $e->getStripeCode(), $e);
        }
    }

    public function getSubscription(string $subscriptionId): ?Subscription
    {
        try {
            return $this->toSubscription($this->getClient()->subscriptions->retrieve($subscriptionId));
        } catch (\Stripe\Exception\ApiErrorException) {
            return null;
        }
    }

    /**
     * Every item, not just the first: planId/currency/interval come from the
     * first (base) item, amount is the recurring total across all of them.
     */
    private function toSubscription(\Stripe\Subscription $sub): Subscription
    {
        $items = array_map(fn ($item) => new SubscriptionItem(
            id: $item->id,
            priceId: $item->price->id,
            quantity: (int) ($item->quantity ?? 1),
            unitAmount: (int) $item->price->unit_amount,
            currency: mb_strtoupper($item->price->currency),
            interval: $item->price->recurring->interval,
            intervalCount: (int) ($item->price->recurring->interval_count ?? 1),
        ), $sub->items->data);

        $first = $items[0];
        $at = fn ($ts) => $ts ? DateTimeImmutable::createFromFormat('U', (string) $ts) : null;

        return new Subscription(
            id: $sub->id,
            customerId: $sub->customer,
            planId: $first->priceId,
            status: $this->mapStripeSubscriptionStatus($sub->status),
            amount: array_sum(array_map(fn (SubscriptionItem $item) => $item->unitAmount * $item->quantity, $items)),
            currency: $first->currency,
            interval: $first->interval,
            driver: $this->getName(),
            currentPeriodStart: $at($sub->current_period_start),
            currentPeriodEnd: $at($sub->current_period_end),
            trialStart: $at($sub->trial_start ?? null),
            trialEnd: $at($sub->trial_end ?? null),
            canceledAt: $at($sub->canceled_at ?? null),
            cancelAtPeriodEnd: (bool) $sub->cancel_at_period_end,
            raw: $sub->toArray(),
            items: $items,
        );
    }

    /**
     * The base Price plus any extra recurring items from $options['items'].
     * Only Price IDs and whole quantities pass: amounts always come from the
     * Prices in Stripe, never from the caller.
     *
     * @param  array<string, mixed>  $options
     * @return list<array{price: string, quantity: int}>
     */
    private function lineItems(string $priceId, array $options): array
    {
        $quantity = $options['quantity'] ?? 1;
        if (!is_int($quantity) || $quantity < 1) {
            $this->invalid('quantity must be a positive integer.');
        }

        $lines = [['price' => $priceId, 'quantity' => $quantity]];
        $allowed = config('payment-gateway.checkout.allowed_prices');
        $extra = $options['items'] ?? [];
        if (!is_array($extra) || !array_is_list($extra)) {
            $this->invalid('items must be a list of [price_id, quantity].');
        }

        foreach ($extra as $item) {
            if (!is_array($item) || array_diff(array_keys($item), ['price_id', 'quantity']) !== []
                || !is_string($item['price_id'] ?? null) || $item['price_id'] === ''
                || !is_int($item['quantity'] ?? null) || $item['quantity'] < 1) {
                $this->invalid('Each item needs a price_id and a positive integer quantity, and nothing else.');
            }
            if (in_array($item['price_id'], array_column($lines, 'price'), true)) {
                $this->invalid("Price {$item['price_id']} appears more than once.");
            }
            $lines[] = ['price' => $item['price_id'], 'quantity' => $item['quantity']];
        }

        // An app that lists its catalog never lets a browser-supplied Price through.
        if (is_array($allowed)) {
            foreach ($lines as $line) {
                if (!in_array($line['price'], $allowed, true)) {
                    $this->invalid("Price {$line['price']} is not in the allowed catalog.");
                }
            }
        }

        return $lines;
    }

    private function invalid(string $message): never
    {
        throw new PaymentException($message, driver: $this->getName());
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

            // With a base + seat item, items[0] may be the wrong one to swap.
            if (count($sub->items->data) > 1) {
                $this->invalid('Subscription has several items; change them by ID with updateSubscriptionItems().');
            }

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

    /**
     * Change subscription items by ID. Items not listed are left alone, so
     * changing the seat count can never touch the base plan.
     *
     * Each change is one of:
     *   ['id' => 'si_…', 'quantity' => 5]             set a quantity (absolute, so a retry can't double it)
     *   ['id' => 'si_…', 'price_id' => 'price_…']     swap the item's Price (quantity optional)
     *   ['id' => 'si_…', 'deleted' => true]            remove the item
     *   ['price_id' => 'price_…', 'quantity' => 2]     add an item (quantity optional, Stripe defaults to 1)
     *
     * Options:
     *   proration_behavior  create_prorations (default) | none | always_invoice
     *   idempotency_key     one per intended change; Stripe replays the first result for a retry
     *
     * @param  list<array<string, mixed>>  $changes
     * @param  array<string, mixed>  $options
     */
    public function updateSubscriptionItems(string $subscriptionId, array $changes, array $options = []): Subscription
    {
        $proration = $options['proration_behavior'] ?? 'create_prorations';
        if (!in_array($proration, ['create_prorations', 'none', 'always_invoice'], true)) {
            $this->invalid('proration_behavior must be create_prorations, none or always_invoice.');
        }

        if ($changes === [] || !array_is_list($changes)) {
            $this->invalid('Pass a list of item changes.');
        }

        $items = [];
        $seen = [];
        foreach ($changes as $change) {
            if (!is_array($change) || array_diff(array_keys($change), ['id', 'price_id', 'quantity', 'deleted']) !== []) {
                $this->invalid('An item change may only set id, price_id, quantity or deleted.');
            }

            $id = $change['id'] ?? null;
            $price = $change['price_id'] ?? null;
            $quantity = $change['quantity'] ?? null;
            $delete = array_key_exists('deleted', $change);

            $valid = ($id === null || (is_string($id) && $id !== '' && !isset($seen[$id])))
                && ($price === null || (is_string($price) && $price !== ''))
                && ($quantity === null || (is_int($quantity) && $quantity >= 1))
                && ($delete
                    ? $change['deleted'] === true && $id !== null && $price === null && $quantity === null
                    : ($id === null ? $price !== null : $price !== null || $quantity !== null));

            if (!$valid) {
                $this->invalid('Invalid item change: '.json_encode(array_keys($change)).'.');
            }

            $seen[$id] = true;
            $items[] = array_filter(['id' => $id, 'price' => $price, 'quantity' => $quantity, 'deleted' => $delete ?: null], fn ($v) => $v !== null);
        }

        try {
            $sub = $this->getClient()->subscriptions->update(
                $subscriptionId,
                ['items' => $items, 'proration_behavior' => $proration],
                isset($options['idempotency_key']) ? ['idempotency_key' => (string) $options['idempotency_key']] : [],
            );

            return $this->toSubscription($sub);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $this->throwException("Failed to update subscription items: {$e->getMessage()}", $e->getStripeCode(), $e);
        }
    }

    // ========================================
    // SupportsWebhooks
    // ========================================

    public function verifyWebhookSignature(Request $request): bool
    {
        $signature = $request->header('Stripe-Signature');
        $secret = $this->getWebhookSecret();

        // empty(), not null: `STRIPE_WEBHOOK_SECRET=` arrives as "". An HMAC
        // keyed with "" is one anyone can compute, and stripe-php < 22 accepts
        // it, so an unset or blank secret must fail closed here, not in the library.
        if (empty($signature) || trim((string) $secret) === '' || $secret === '0') {
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

    /**
     * Create a Stripe Checkout Session for a subscription.
     *
     * @param  array<string, mixed>  $options
     */
    public function createCheckoutSession(
        Customer $customer,
        string $priceId,
        string $successUrl,
        string $cancelUrl,
        array $options = []
    ): CheckoutSession {
        // 'subscription' (default) for recurring Prices; 'payment' for one-time
        // purchases such as products or credit packs.
        $mode = $options['mode'] ?? 'subscription';
        if (!in_array($mode, ['subscription', 'payment'], true)) {
            $this->invalid('mode must be subscription or payment.');
        }

        $params = [
            'mode' => $mode,
            'customer' => $customer->id,
            'line_items' => $this->lineItems($priceId, $options),
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
        ];

        if ($mode === 'payment') {
            if (isset($options['payment_method_collection']) || Trial::fromOptions($options) !== null) {
                $this->invalid('Trials and payment_method_collection only apply to subscription checkout.');
            }

            // Fulfil from the payment_intent.succeeded webhook, never the redirect: it sees
            // the PaymentIntent, so the order metadata has to travel on it.
            if (isset($options['metadata'])) {
                $params['payment_intent_data'] = ['metadata' => $options['metadata']];
            }
        }

        // Checkout requires a trial_end at least 48 hours out.
        $trial = $mode === 'subscription' ? $this->trialParams($customer, $priceId, $options, 48 * 3600) : [];
        if ($trial !== []) {
            $params['subscription_data'] = $trial;
        }

        // 'if_required' lets a trial start without a card; 'always' collects one up front.
        if (isset($options['payment_method_collection'])) {
            if (!in_array($options['payment_method_collection'], ['always', 'if_required'], true)) {
                throw new PaymentException('payment_method_collection must be always or if_required.');
            }
            $params['payment_method_collection'] = $options['payment_method_collection'];
        }

        if (isset($options['metadata'])) {
            $params['metadata'] = $options['metadata'];
        }

        $session = $this->getClient()->checkout->sessions->create($params);

        return new CheckoutSession(
            id: $session->id,
            url: $session->url,
            status: $session->status ?? 'open',
            driver: $this->getName(),
            customerId: $session->customer,
            subscriptionId: $session->subscription,
            raw: $session->toArray(),
        );
    }

    /**
     * Validated trial params, after the host app's eligibility policy (if bound) agrees.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function trialParams(Customer $customer, string $priceId, array $options, int $minLeadSeconds = 0): array
    {
        $trial = Trial::fromOptions($options, $minLeadSeconds);

        if ($trial === null) {
            return [];
        }

        if (app()->bound(TrialEligibility::class) && !app(TrialEligibility::class)->allowsTrial($customer, $priceId, $options)) {
            throw new PaymentException('This customer is not eligible for a trial.', driver: $this->getName());
        }

        return $trial->toStripe();
    }

    /**
     * Create a Stripe Billing Portal session.
     */
    public function createBillingPortalSession(string $customerId, string $returnUrl): string
    {
        $session = $this->getClient()->billingPortal->sessions->create([
            'customer' => $customerId,
            'return_url' => $returnUrl,
        ]);

        return $session->url;
    }
}

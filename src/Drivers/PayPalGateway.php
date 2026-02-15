<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Drivers;

use DateTimeImmutable;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use LaravelPlus\PaymentGateway\Contracts\SupportsCustomers;
use LaravelPlus\PaymentGateway\Contracts\SupportsRefunds;
use LaravelPlus\PaymentGateway\Contracts\SupportsSubscriptions;
use LaravelPlus\PaymentGateway\Contracts\SupportsWebhooks;
use LaravelPlus\PaymentGateway\DTOs\Customer;
use LaravelPlus\PaymentGateway\DTOs\PaymentIntent;
use LaravelPlus\PaymentGateway\DTOs\PaymentMethodData;
use LaravelPlus\PaymentGateway\DTOs\PaymentResult;
use LaravelPlus\PaymentGateway\DTOs\Refund;
use LaravelPlus\PaymentGateway\DTOs\Subscription;
use LaravelPlus\PaymentGateway\DTOs\SubscriptionPlan;
use LaravelPlus\PaymentGateway\DTOs\WebhookPayload;
use LaravelPlus\PaymentGateway\Enums\PaymentStatus;
use LaravelPlus\PaymentGateway\Enums\SubscriptionStatus;
use LaravelPlus\PaymentGateway\Exceptions\PaymentException;

/**
 * PayPal Payment Gateway Driver.
 *
 * Requires: paypal/paypal-checkout-sdk package
 * All amounts are in cents.
 */
final class PayPalGateway extends AbstractPaymentGateway implements SupportsCustomers, SupportsRefunds, SupportsSubscriptions, SupportsWebhooks
{
    public function getName(): string
    {
        return 'paypal';
    }

    public function getDisplayName(): string
    {
        return 'PayPal';
    }

    public function isAvailable(): bool
    {
        return !empty($this->getConfig('client_id'))
            && !empty($this->getConfig('client_secret'));
    }

    /**
     * @return array<string>
     */
    public function getSupportedCurrencies(): array
    {
        return ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'JPY', 'CHF', 'NOK', 'SEK', 'DKK', 'PLN', 'CZK', 'HUF'];
    }

    /**
     * Check if we're in sandbox mode.
     */
    protected function isSandbox(): bool
    {
        return $this->getConfig('mode', 'sandbox') === 'sandbox';
    }

    /**
     * Get PayPal API base URL.
     */
    protected function getBaseUrl(): string
    {
        return $this->isSandbox()
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';
    }

    /**
     * Get access token.
     */
    protected function getAccessToken(): string
    {
        $clientId = $this->getConfig('client_id');
        $clientSecret = $this->getConfig('client_secret');

        $response = \Illuminate\Support\Facades\Http::withBasicAuth($clientId, $clientSecret)
            ->asForm()
            ->post("{$this->getBaseUrl()}/v1/oauth2/token", [
                'grant_type' => 'client_credentials',
            ]);

        if (!$response->successful()) {
            throw new PaymentException('Failed to get PayPal access token');
        }

        return $response->json('access_token');
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
            $token = $this->getAccessToken();
            $response = \Illuminate\Support\Facades\Http::withToken($token)
                ->post("{$this->getBaseUrl()}/v2/checkout/orders", [
                    'intent' => 'CAPTURE',
                    'purchase_units' => [[
                        'amount' => [
                            'currency_code' => mb_strtoupper($currency),
                            'value' => $this->toDecimalString($amount),
                        ],
                        'custom_id' => $metadata['order_id'] ?? Str::random(16),
                    ]],
                    'application_context' => [
                        'return_url' => $metadata['return_url'] ?? config('app.url').'/payment/paypal/return',
                        'cancel_url' => $metadata['cancel_url'] ?? config('app.url').'/payment/paypal/cancel',
                    ],
                ]);

            if (!$response->successful()) {
                throw new PaymentException('Failed to create PayPal order: '.$response->body());
            }

            $order = $response->json();
            $approvalUrl = collect($order['links'])->firstWhere('rel', 'approve')['href'] ?? null;

            $this->log('info', 'PayPal order created', ['order_id' => $order['id'], 'amount' => $amount]);

            return new PaymentIntent(
                id: $order['id'],
                clientSecret: $approvalUrl ?? $order['id'],
                status: PaymentStatus::Pending,
                amount: $amount,
                currency: mb_strtoupper($currency),
                driver: $this->getName(),
                customerId: $customer?->id,
                returnUrl: $approvalUrl,
                metadata: array_merge($metadata, ['approval_url' => $approvalUrl]),
                raw: $order,
            );
        } catch (Exception $e) {
            if ($e instanceof PaymentException) {
                throw $e;
            }
            $this->throwException("Failed to create PayPal order: {$e->getMessage()}", null, $e);
        }
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function charge(
        int $amount,
        string $currency,
        string $paymentMethodId, // PayPal order ID
        array $options = []
    ): PaymentResult {
        try {
            $token = $this->getAccessToken();

            // Capture the order
            $response = \Illuminate\Support\Facades\Http::withToken($token)
                ->post("{$this->getBaseUrl()}/v2/checkout/orders/{$paymentMethodId}/capture");

            if (!$response->successful()) {
                throw new PaymentException('Failed to capture PayPal order: '.$response->body());
            }

            $capture = $response->json();
            $status = mb_strtolower($capture['status'] ?? 'unknown');

            $this->log('info', 'PayPal order captured', ['order_id' => $paymentMethodId, 'status' => $status]);

            return new PaymentResult(
                transactionId: $capture['id'],
                status: $this->mapPayPalStatus($status),
                amount: $amount,
                currency: mb_strtoupper($currency),
                driver: $this->getName(),
                paymentMethodId: $paymentMethodId,
                customerId: $options['customer_id'] ?? null,
                metadata: $options['metadata'] ?? [],
                raw: $capture,
            );
        } catch (Exception $e) {
            if ($e instanceof PaymentException) {
                throw $e;
            }
            $this->throwException("Failed to capture PayPal order: {$e->getMessage()}", null, $e);
        }
    }

    public function getPayment(string $transactionId): ?PaymentResult
    {
        try {
            $token = $this->getAccessToken();

            $response = \Illuminate\Support\Facades\Http::withToken($token)
                ->get("{$this->getBaseUrl()}/v2/checkout/orders/{$transactionId}");

            if (!$response->successful()) {
                return null;
            }

            $order = $response->json();
            $purchaseUnit = $order['purchase_units'][0] ?? [];
            $amount = $purchaseUnit['amount'] ?? [];

            return new PaymentResult(
                transactionId: $order['id'],
                status: $this->mapPayPalStatus(mb_strtolower($order['status'])),
                amount: (int) (((float) ($amount['value'] ?? 0)) * 100),
                currency: mb_strtoupper($amount['currency_code'] ?? 'USD'),
                driver: $this->getName(),
                raw: $order,
            );
        } catch (Exception) {
            return null;
        }
    }

    public function cancel(string $transactionId): bool
    {
        // PayPal orders can't be explicitly canceled via API
        // They expire after a certain time if not completed
        $this->log('info', 'PayPal order cancel requested', ['order_id' => $transactionId]);

        return true;
    }

    // ========================================
    // SupportsRefunds
    // ========================================

    public function refund(string $transactionId, ?string $reason = null): Refund
    {
        try {
            $token = $this->getAccessToken();

            // Get the capture ID from the order
            $orderResponse = \Illuminate\Support\Facades\Http::withToken($token)
                ->get("{$this->getBaseUrl()}/v2/checkout/orders/{$transactionId}");

            if (!$orderResponse->successful()) {
                throw new PaymentException('Failed to get PayPal order for refund');
            }

            $order = $orderResponse->json();
            $captureId = $order['purchase_units'][0]['payments']['captures'][0]['id'] ?? null;

            if (!$captureId) {
                throw new PaymentException('No capture found for PayPal order');
            }

            $response = \Illuminate\Support\Facades\Http::withToken($token)
                ->post("{$this->getBaseUrl()}/v2/payments/captures/{$captureId}/refund", [
                    'note_to_payer' => $reason,
                ]);

            if (!$response->successful()) {
                throw new PaymentException('Failed to create PayPal refund: '.$response->body());
            }

            $refund = $response->json();

            return new Refund(
                id: $refund['id'],
                transactionId: $transactionId,
                status: mb_strtolower($refund['status']),
                amount: (int) (((float) $refund['amount']['value']) * 100),
                currency: mb_strtoupper($refund['amount']['currency_code']),
                driver: $this->getName(),
                reason: $reason,
                raw: $refund,
            );
        } catch (Exception $e) {
            if ($e instanceof PaymentException) {
                throw $e;
            }
            $this->throwException("Failed to create PayPal refund: {$e->getMessage()}", null, $e);
        }
    }

    public function partialRefund(string $transactionId, int $amount, ?string $reason = null): Refund
    {
        // Similar to full refund but with amount specified
        try {
            $token = $this->getAccessToken();

            $orderResponse = \Illuminate\Support\Facades\Http::withToken($token)
                ->get("{$this->getBaseUrl()}/v2/checkout/orders/{$transactionId}");

            $order = $orderResponse->json();
            $captureId = $order['purchase_units'][0]['payments']['captures'][0]['id'] ?? null;
            $currency = $order['purchase_units'][0]['amount']['currency_code'] ?? 'USD';

            $response = \Illuminate\Support\Facades\Http::withToken($token)
                ->post("{$this->getBaseUrl()}/v2/payments/captures/{$captureId}/refund", [
                    'amount' => [
                        'value' => $this->toDecimalString($amount),
                        'currency_code' => $currency,
                    ],
                    'note_to_payer' => $reason,
                ]);

            $refund = $response->json();

            return new Refund(
                id: $refund['id'],
                transactionId: $transactionId,
                status: mb_strtolower($refund['status']),
                amount: $amount,
                currency: mb_strtoupper($currency),
                driver: $this->getName(),
                reason: $reason,
                raw: $refund,
            );
        } catch (Exception $e) {
            $this->throwException("Failed to create PayPal partial refund: {$e->getMessage()}", null, $e);
        }
    }

    public function getRefund(string $refundId): ?Refund
    {
        try {
            $token = $this->getAccessToken();

            $response = \Illuminate\Support\Facades\Http::withToken($token)
                ->get("{$this->getBaseUrl()}/v2/payments/refunds/{$refundId}");

            if (!$response->successful()) {
                return null;
            }

            $refund = $response->json();

            // Extract capture ID from the "up" link (points to the original capture)
            $captureLink = collect($refund['links'] ?? [])->firstWhere('rel', 'up');
            $transactionId = $captureLink ? basename($captureLink['href']) : 'unknown';

            return new Refund(
                id: $refund['id'],
                transactionId: $transactionId,
                status: mb_strtolower($refund['status']),
                amount: (int) (((float) $refund['amount']['value']) * 100),
                currency: mb_strtoupper($refund['amount']['currency_code']),
                driver: $this->getName(),
                raw: $refund,
            );
        } catch (Exception) {
            return null;
        }
    }

    /**
     * @return array<Refund>
     */
    public function getRefundsForTransaction(string $transactionId): array
    {
        // Query local database for refunds since PayPal doesn't provide a direct API
        $refunds = \LaravelPlus\PaymentGateway\Models\Refund::where('driver', 'paypal')
            ->whereHas('transaction', fn ($q) => $q->where('provider_id', $transactionId))
            ->get();

        return $refunds->map(fn ($refund) => $refund->toDto())->all();
    }

    // ========================================
    // SupportsCustomers
    // ========================================

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function createCustomer(string $email, ?string $name = null, array $metadata = []): Customer
    {
        // PayPal doesn't have a standalone customer creation API.
        // Customers are created implicitly when they make payments.
        // We return a local representation.
        $id = 'paypal_'.Str::random(16);

        $this->log('info', 'PayPal customer created locally', ['id' => $id, 'email' => $email]);

        return new Customer(
            id: $id,
            email: $email,
            name: $name,
            metadata: $metadata,
        );
    }

    public function getCustomer(string $customerId): ?Customer
    {
        // PayPal does not have a customer retrieval API.
        // Look up locally stored customer data.
        $paymentCustomer = \LaravelPlus\PaymentGateway\Models\PaymentCustomer::where('provider_id', $customerId)
            ->where('driver', 'paypal')
            ->first();

        if (!$paymentCustomer) {
            return null;
        }

        $user = $paymentCustomer->user;

        return new Customer(
            id: $customerId,
            email: $user?->email,
            name: $user?->name,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateCustomer(string $customerId, array $data): Customer
    {
        return new Customer(
            id: $customerId,
            email: $data['email'] ?? null,
            name: $data['name'] ?? null,
            metadata: $data['metadata'] ?? [],
        );
    }

    public function deleteCustomer(string $customerId): bool
    {
        $this->log('info', 'PayPal customer deleted locally', ['id' => $customerId]);

        return true;
    }

    public function attachPaymentMethod(string $customerId, string $paymentMethodId): PaymentMethodData
    {
        return new PaymentMethodData(
            id: $paymentMethodId,
            type: 'paypal',
            driver: $this->getName(),
            paypalEmail: $paymentMethodId,
        );
    }

    public function detachPaymentMethod(string $paymentMethodId): bool
    {
        return true;
    }

    /**
     * @return array<PaymentMethodData>
     */
    public function getPaymentMethods(string $customerId): array
    {
        $methods = \LaravelPlus\PaymentGateway\Models\PaymentMethod::where('payment_customer_id', $customerId)
            ->where('driver', 'paypal')
            ->get();

        return $methods->map(fn ($m) => new PaymentMethodData(
            id: $m->provider_id,
            type: 'paypal',
            driver: $this->getName(),
            paypalEmail: $m->metadata['email'] ?? null,
            isDefault: $m->is_default,
        ))->all();
    }

    public function setDefaultPaymentMethod(string $customerId, string $paymentMethodId): bool
    {
        return true;
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
            $token = $this->getAccessToken();

            // Create product first
            $productResponse = \Illuminate\Support\Facades\Http::withToken($token)
                ->post("{$this->getBaseUrl()}/v1/catalogs/products", [
                    'name' => $name,
                    'description' => $options['description'] ?? $name,
                    'type' => 'SERVICE',
                    'category' => $options['category'] ?? 'SOFTWARE',
                ]);

            if (!$productResponse->successful()) {
                throw new PaymentException('Failed to create PayPal product: '.$productResponse->body());
            }

            $product = $productResponse->json();

            // Create billing plan
            $planResponse = \Illuminate\Support\Facades\Http::withToken($token)
                ->post("{$this->getBaseUrl()}/v1/billing/plans", [
                    'product_id' => $product['id'],
                    'name' => $name,
                    'description' => $options['description'] ?? $name,
                    'billing_cycles' => [[
                        'frequency' => [
                            'interval_unit' => mb_strtoupper($interval),
                            'interval_count' => $options['interval_count'] ?? 1,
                        ],
                        'tenure_type' => 'REGULAR',
                        'sequence' => 1,
                        'total_cycles' => 0,
                        'pricing_scheme' => [
                            'fixed_price' => [
                                'value' => $this->toDecimalString($amount),
                                'currency_code' => mb_strtoupper($currency),
                            ],
                        ],
                    ]],
                    'payment_preferences' => [
                        'auto_bill_outstanding' => true,
                        'payment_failure_threshold' => $options['failure_threshold'] ?? 3,
                    ],
                ]);

            if (!$planResponse->successful()) {
                throw new PaymentException('Failed to create PayPal plan: '.$planResponse->body());
            }

            $plan = $planResponse->json();

            $this->log('info', 'PayPal plan created', ['plan_id' => $plan['id'], 'product_id' => $product['id']]);

            return new SubscriptionPlan(
                id: $plan['id'],
                productId: $product['id'],
                name: $name,
                amount: $amount,
                currency: mb_strtoupper($currency),
                interval: $interval,
                intervalCount: $options['interval_count'] ?? 1,
                driver: $this->getName(),
                description: $options['description'] ?? null,
                raw: $plan,
            );
        } catch (Exception $e) {
            if ($e instanceof PaymentException) {
                throw $e;
            }
            $this->throwException("Failed to create PayPal plan: {$e->getMessage()}", null, $e);
        }
    }

    public function getPlan(string $planId): ?SubscriptionPlan
    {
        try {
            $token = $this->getAccessToken();

            $response = \Illuminate\Support\Facades\Http::withToken($token)
                ->get("{$this->getBaseUrl()}/v1/billing/plans/{$planId}");

            if (!$response->successful()) {
                return null;
            }

            $plan = $response->json();
            $billingCycle = collect($plan['billing_cycles'] ?? [])->firstWhere('tenure_type', 'REGULAR');
            $pricing = $billingCycle['pricing_scheme']['fixed_price'] ?? [];

            return new SubscriptionPlan(
                id: $plan['id'],
                productId: $plan['product_id'],
                name: $plan['name'],
                amount: (int) round(((float) ($pricing['value'] ?? 0)) * 100),
                currency: mb_strtoupper($pricing['currency_code'] ?? 'USD'),
                interval: mb_strtolower($billingCycle['frequency']['interval_unit'] ?? 'month'),
                intervalCount: $billingCycle['frequency']['interval_count'] ?? 1,
                driver: $this->getName(),
                description: $plan['description'] ?? null,
                isActive: ($plan['status'] ?? '') === 'ACTIVE',
                raw: $plan,
            );
        } catch (Exception) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function createSubscription(Customer $customer, string $planId, string $paymentMethodId, array $options = []): Subscription
    {
        try {
            $token = $this->getAccessToken();

            $params = [
                'plan_id' => $planId,
                'subscriber' => [
                    'name' => ['given_name' => $customer->name ?? ''],
                    'email_address' => $customer->email,
                ],
                'application_context' => [
                    'return_url' => $options['return_url'] ?? config('app.url').'/payment/paypal/subscription/return',
                    'cancel_url' => $options['cancel_url'] ?? config('app.url').'/payment/paypal/subscription/cancel',
                    'user_action' => 'SUBSCRIBE_NOW',
                ],
            ];

            if (!empty($options['start_time'])) {
                $params['start_time'] = $options['start_time'];
            }

            $response = \Illuminate\Support\Facades\Http::withToken($token)
                ->post("{$this->getBaseUrl()}/v1/billing/subscriptions", $params);

            if (!$response->successful()) {
                throw new PaymentException('Failed to create PayPal subscription: '.$response->body());
            }

            $sub = $response->json();

            $this->log('info', 'PayPal subscription created', ['subscription_id' => $sub['id']]);

            return new Subscription(
                id: $sub['id'],
                customerId: $customer->id ?? '',
                planId: $planId,
                status: $this->mapPayPalSubscriptionStatus($sub['status'] ?? 'APPROVAL_PENDING'),
                amount: 0,
                currency: 'USD',
                interval: 'month',
                driver: $this->getName(),
                raw: $sub,
            );
        } catch (Exception $e) {
            if ($e instanceof PaymentException) {
                throw $e;
            }
            $this->throwException("Failed to create PayPal subscription: {$e->getMessage()}", null, $e);
        }
    }

    public function getSubscription(string $subscriptionId): ?Subscription
    {
        try {
            $token = $this->getAccessToken();

            $response = \Illuminate\Support\Facades\Http::withToken($token)
                ->get("{$this->getBaseUrl()}/v1/billing/subscriptions/{$subscriptionId}");

            if (!$response->successful()) {
                return null;
            }

            $sub = $response->json();
            $billingInfo = $sub['billing_info'] ?? [];
            $lastPayment = $billingInfo['last_payment']['amount'] ?? [];

            return new Subscription(
                id: $sub['id'],
                customerId: $sub['subscriber']['payer_id'] ?? '',
                planId: $sub['plan_id'],
                status: $this->mapPayPalSubscriptionStatus($sub['status']),
                amount: (int) round(((float) ($lastPayment['value'] ?? 0)) * 100),
                currency: mb_strtoupper($lastPayment['currency_code'] ?? 'USD'),
                interval: 'month',
                driver: $this->getName(),
                currentPeriodStart: isset($sub['start_time']) ? new DateTimeImmutable($sub['start_time']) : null,
                currentPeriodEnd: isset($billingInfo['next_billing_time']) ? new DateTimeImmutable($billingInfo['next_billing_time']) : null,
                cancelAtPeriodEnd: false,
                raw: $sub,
            );
        } catch (Exception) {
            return null;
        }
    }

    public function cancelSubscription(string $subscriptionId, bool $immediately = false): bool
    {
        try {
            $token = $this->getAccessToken();

            $response = \Illuminate\Support\Facades\Http::withToken($token)
                ->post("{$this->getBaseUrl()}/v1/billing/subscriptions/{$subscriptionId}/cancel", [
                    'reason' => 'Canceled by user',
                ]);

            return $response->successful() || $response->status() === 204;
        } catch (Exception) {
            return false;
        }
    }

    public function pauseSubscription(string $subscriptionId): bool
    {
        try {
            $token = $this->getAccessToken();

            $response = \Illuminate\Support\Facades\Http::withToken($token)
                ->post("{$this->getBaseUrl()}/v1/billing/subscriptions/{$subscriptionId}/suspend", [
                    'reason' => 'Paused by user',
                ]);

            return $response->successful() || $response->status() === 204;
        } catch (Exception) {
            return false;
        }
    }

    public function resumeSubscription(string $subscriptionId): bool
    {
        try {
            $token = $this->getAccessToken();

            $response = \Illuminate\Support\Facades\Http::withToken($token)
                ->post("{$this->getBaseUrl()}/v1/billing/subscriptions/{$subscriptionId}/activate", [
                    'reason' => 'Resumed by user',
                ]);

            return $response->successful() || $response->status() === 204;
        } catch (Exception) {
            return false;
        }
    }

    public function updateSubscription(string $subscriptionId, string $newPlanId): Subscription
    {
        try {
            $token = $this->getAccessToken();

            $response = \Illuminate\Support\Facades\Http::withToken($token)
                ->post("{$this->getBaseUrl()}/v1/billing/subscriptions/{$subscriptionId}/revise", [
                    'plan_id' => $newPlanId,
                ]);

            if (!$response->successful()) {
                throw new PaymentException('Failed to update PayPal subscription: '.$response->body());
            }

            return $this->getSubscription($subscriptionId)
                ?? throw new PaymentException('Failed to retrieve updated subscription');
        } catch (Exception $e) {
            if ($e instanceof PaymentException) {
                throw $e;
            }
            $this->throwException("Failed to update PayPal subscription: {$e->getMessage()}", null, $e);
        }
    }

    // ========================================
    // SupportsWebhooks
    // ========================================

    public function verifyWebhookSignature(Request $request): bool
    {
        $webhookId = $this->getConfig('webhook_id');

        if (empty($webhookId)) {
            return false;
        }

        try {
            $token = $this->getAccessToken();

            $response = \Illuminate\Support\Facades\Http::withToken($token)
                ->post("{$this->getBaseUrl()}/v1/notifications/verify-webhook-signature", [
                    'auth_algo' => $request->header('PAYPAL-AUTH-ALGO'),
                    'cert_url' => $request->header('PAYPAL-CERT-URL'),
                    'transmission_id' => $request->header('PAYPAL-TRANSMISSION-ID'),
                    'transmission_sig' => $request->header('PAYPAL-TRANSMISSION-SIG'),
                    'transmission_time' => $request->header('PAYPAL-TRANSMISSION-TIME'),
                    'webhook_id' => $webhookId,
                    'webhook_event' => json_decode($request->getContent(), true),
                ]);

            return $response->json('verification_status') === 'SUCCESS';
        } catch (Exception) {
            return false;
        }
    }

    public function parseWebhook(Request $request): WebhookPayload
    {
        $payload = json_decode($request->getContent(), true);

        return new WebhookPayload(
            id: $payload['id'],
            type: $payload['event_type'],
            driver: $this->getName(),
            data: $payload['resource'] ?? [],
            createdAt: isset($payload['create_time']) ? new DateTimeImmutable($payload['create_time']) : null,
            raw: $payload,
        );
    }

    public function getWebhookSecret(): ?string
    {
        return $this->getConfig('webhook_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function handleWebhook(WebhookPayload $payload): array
    {
        $this->log('info', 'PayPal webhook received', ['type' => $payload->type, 'id' => $payload->id]);

        return [
            'handled' => true,
            'type' => $payload->type,
        ];
    }

    // ========================================
    // Helpers
    // ========================================

    protected function mapPayPalStatus(string $status): PaymentStatus
    {
        return match ($status) {
            'completed', 'approved' => PaymentStatus::Succeeded,
            'created', 'saved', 'payer_action_required' => PaymentStatus::Pending,
            'voided' => PaymentStatus::Canceled,
            default => PaymentStatus::Failed,
        };
    }

    protected function mapPayPalSubscriptionStatus(string $status): SubscriptionStatus
    {
        return match ($status) {
            'ACTIVE' => SubscriptionStatus::Active,
            'APPROVAL_PENDING', 'APPROVED' => SubscriptionStatus::Incomplete,
            'SUSPENDED' => SubscriptionStatus::Paused,
            'CANCELLED' => SubscriptionStatus::Canceled,
            'EXPIRED' => SubscriptionStatus::Expired,
            default => SubscriptionStatus::Incomplete,
        };
    }
}

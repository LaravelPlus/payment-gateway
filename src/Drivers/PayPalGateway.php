<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Drivers;

use DateTimeImmutable;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Nejcc\PaymentGateway\Contracts\SupportsRefunds;
use Nejcc\PaymentGateway\Contracts\SupportsWebhooks;
use Nejcc\PaymentGateway\DTOs\Customer;
use Nejcc\PaymentGateway\DTOs\PaymentIntent;
use Nejcc\PaymentGateway\DTOs\PaymentResult;
use Nejcc\PaymentGateway\DTOs\Refund;
use Nejcc\PaymentGateway\DTOs\WebhookPayload;
use Nejcc\PaymentGateway\Enums\PaymentStatus;
use Nejcc\PaymentGateway\Exceptions\PaymentException;

/**
 * PayPal Payment Gateway Driver.
 *
 * Requires: paypal/paypal-checkout-sdk package
 * All amounts are in cents.
 */
final class PayPalGateway extends AbstractPaymentGateway implements SupportsRefunds, SupportsWebhooks
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
            $amountDecimal = number_format($amount / 100, 2, '.', '');

            $response = \Illuminate\Support\Facades\Http::withToken($token)
                ->post("{$this->getBaseUrl()}/v2/checkout/orders", [
                    'intent' => 'CAPTURE',
                    'purchase_units' => [[
                        'amount' => [
                            'currency_code' => mb_strtoupper($currency),
                            'value' => $amountDecimal,
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
            $amountDecimal = number_format($amount / 100, 2, '.', '');

            $orderResponse = \Illuminate\Support\Facades\Http::withToken($token)
                ->get("{$this->getBaseUrl()}/v2/checkout/orders/{$transactionId}");

            $order = $orderResponse->json();
            $captureId = $order['purchase_units'][0]['payments']['captures'][0]['id'] ?? null;
            $currency = $order['purchase_units'][0]['amount']['currency_code'] ?? 'USD';

            $response = \Illuminate\Support\Facades\Http::withToken($token)
                ->post("{$this->getBaseUrl()}/v2/payments/captures/{$captureId}/refund", [
                    'amount' => [
                        'value' => $amountDecimal,
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

            return new Refund(
                id: $refund['id'],
                transactionId: $refund['links'][0]['href'] ?? 'unknown', // PayPal doesn't include original transaction ID directly
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
        // PayPal doesn't provide a direct API for this
        // You would need to track refunds in your database
        return [];
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
}

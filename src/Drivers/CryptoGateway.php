<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Drivers;

use DateTimeImmutable;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Nejcc\PaymentGateway\Contracts\SupportsWebhooks;
use Nejcc\PaymentGateway\DTOs\Customer;
use Nejcc\PaymentGateway\DTOs\PaymentIntent;
use Nejcc\PaymentGateway\DTOs\PaymentResult;
use Nejcc\PaymentGateway\DTOs\WebhookPayload;
use Nejcc\PaymentGateway\Enums\PaymentStatus;
use Nejcc\PaymentGateway\Exceptions\PaymentException;

/**
 * Cryptocurrency Payment Gateway Driver.
 *
 * Supports: Coinbase Commerce, BTCPay Server, or custom implementations.
 * All amounts are in cents (fiat equivalent).
 */
final class CryptoGateway extends AbstractPaymentGateway implements SupportsWebhooks
{
    public function getName(): string
    {
        return 'crypto';
    }

    public function getDisplayName(): string
    {
        return 'Cryptocurrency';
    }

    public function isAvailable(): bool
    {
        return !empty($this->getConfig('api_key'));
    }

    /**
     * @return array<string>
     */
    public function getSupportedCurrencies(): array
    {
        // Fiat currencies that can be converted to crypto
        return ['USD', 'EUR', 'GBP'];
    }

    /**
     * Get supported cryptocurrencies.
     *
     * @return array<string>
     */
    public function getSupportedCryptoCurrencies(): array
    {
        return $this->getConfig('supported_currencies', ['BTC', 'ETH', 'USDT', 'USDC']);
    }

    /**
     * Get the crypto provider type.
     */
    protected function getProvider(): string
    {
        return $this->getConfig('provider', 'coinbase');
    }

    /**
     * Get API base URL based on provider.
     */
    protected function getBaseUrl(): string
    {
        return match ($this->getProvider()) {
            'coinbase' => 'https://api.commerce.coinbase.com',
            'btcpay' => $this->getConfig('btcpay_url', 'https://your-btcpay.example.com'),
            default => $this->getConfig('api_url', ''),
        };
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
        $provider = $this->getProvider();

        if ($provider === 'coinbase') {
            return $this->createCoinbaseCharge($amount, $currency, $customer, $metadata);
        }

        // Fallback to generic implementation
        return $this->createGenericCryptoCharge($amount, $currency, $customer, $metadata);
    }

    /**
     * Create a Coinbase Commerce charge.
     *
     * @param  array<string, mixed>  $metadata
     */
    protected function createCoinbaseCharge(
        int $amount,
        string $currency,
        ?Customer $customer,
        array $metadata
    ): PaymentIntent {
        try {
            $amountDecimal = number_format($amount / 100, 2, '.', '');

            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'X-CC-Api-Key' => $this->getConfig('api_key'),
                'X-CC-Version' => '2018-03-22',
            ])->post("{$this->getBaseUrl()}/charges", [
                'name' => $metadata['name'] ?? 'Payment',
                'description' => $metadata['description'] ?? 'Crypto payment',
                'pricing_type' => 'fixed_price',
                'local_price' => [
                    'amount' => $amountDecimal,
                    'currency' => mb_strtoupper($currency),
                ],
                'metadata' => $metadata,
                'redirect_url' => $metadata['return_url'] ?? config('app.url').'/payment/crypto/return',
                'cancel_url' => $metadata['cancel_url'] ?? config('app.url').'/payment/crypto/cancel',
            ]);

            if (!$response->successful()) {
                throw new PaymentException('Failed to create Coinbase charge: '.$response->body());
            }

            $charge = $response->json('data');

            $this->log('info', 'Coinbase charge created', ['charge_id' => $charge['id'], 'amount' => $amount]);

            return new PaymentIntent(
                id: $charge['id'],
                clientSecret: $charge['hosted_url'],
                status: PaymentStatus::Pending,
                amount: $amount,
                currency: mb_strtoupper($currency),
                driver: $this->getName(),
                customerId: $customer?->id,
                returnUrl: $charge['hosted_url'],
                expiresAt: new DateTimeImmutable($charge['expires_at']),
                metadata: array_merge($metadata, [
                    'hosted_url' => $charge['hosted_url'],
                    'addresses' => $charge['addresses'] ?? [],
                ]),
                raw: $charge,
            );
        } catch (Exception $e) {
            if ($e instanceof PaymentException) {
                throw $e;
            }
            $this->throwException("Failed to create crypto charge: {$e->getMessage()}", null, $e);
        }
    }

    /**
     * Create a generic crypto charge (for custom implementations).
     *
     * @param  array<string, mixed>  $metadata
     */
    protected function createGenericCryptoCharge(
        int $amount,
        string $currency,
        ?Customer $customer,
        array $metadata
    ): PaymentIntent {
        $intentId = 'crypto_'.Str::random(24);

        // This is a placeholder for custom crypto implementations
        // You would implement your own crypto payment logic here

        return new PaymentIntent(
            id: $intentId,
            clientSecret: $intentId,
            status: PaymentStatus::Pending,
            amount: $amount,
            currency: mb_strtoupper($currency),
            driver: $this->getName(),
            customerId: $customer?->id,
            expiresAt: now()->addHours(1),
            metadata: $metadata,
        );
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function charge(
        int $amount,
        string $currency,
        string $paymentMethodId, // Charge ID for crypto
        array $options = []
    ): PaymentResult {
        // For crypto, we can't "charge" directly
        // The payment is initiated via createPaymentIntent and completed when the blockchain confirms
        // This method returns the current status of the charge

        return $this->getPayment($paymentMethodId) ?? new PaymentResult(
            transactionId: $paymentMethodId,
            status: PaymentStatus::Pending,
            amount: $amount,
            currency: mb_strtoupper($currency),
            driver: $this->getName(),
            metadata: $options['metadata'] ?? [],
        );
    }

    public function getPayment(string $transactionId): ?PaymentResult
    {
        if ($this->getProvider() === 'coinbase') {
            return $this->getCoinbaseCharge($transactionId);
        }

        return null;
    }

    /**
     * Get Coinbase Commerce charge status.
     */
    protected function getCoinbaseCharge(string $chargeId): ?PaymentResult
    {
        try {
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'X-CC-Api-Key' => $this->getConfig('api_key'),
                'X-CC-Version' => '2018-03-22',
            ])->get("{$this->getBaseUrl()}/charges/{$chargeId}");

            if (!$response->successful()) {
                return null;
            }

            $charge = $response->json('data');
            $timeline = $charge['timeline'] ?? [];
            $lastEvent = end($timeline);
            $status = $lastEvent['status'] ?? 'NEW';

            $localPrice = $charge['pricing']['local'] ?? [];

            return new PaymentResult(
                transactionId: $charge['id'],
                status: $this->mapCoinbaseStatus($status),
                amount: (int) (((float) ($localPrice['amount'] ?? 0)) * 100),
                currency: mb_strtoupper($localPrice['currency'] ?? 'USD'),
                driver: $this->getName(),
                metadata: [
                    'crypto_payments' => $charge['payments'] ?? [],
                    'addresses' => $charge['addresses'] ?? [],
                ],
                raw: $charge,
            );
        } catch (Exception) {
            return null;
        }
    }

    public function cancel(string $transactionId): bool
    {
        if ($this->getProvider() === 'coinbase') {
            try {
                $response = \Illuminate\Support\Facades\Http::withHeaders([
                    'X-CC-Api-Key' => $this->getConfig('api_key'),
                    'X-CC-Version' => '2018-03-22',
                ])->post("{$this->getBaseUrl()}/charges/{$transactionId}/cancel");

                return $response->successful();
            } catch (Exception) {
                return false;
            }
        }

        return true;
    }

    // ========================================
    // SupportsWebhooks
    // ========================================

    public function verifyWebhookSignature(Request $request): bool
    {
        $secret = $this->getWebhookSecret();

        if (empty($secret)) {
            return false;
        }

        $signature = $request->header('X-CC-Webhook-Signature');

        if (empty($signature)) {
            return false;
        }

        $computedSignature = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($computedSignature, $signature);
    }

    public function parseWebhook(Request $request): WebhookPayload
    {
        $payload = json_decode($request->getContent(), true);

        return new WebhookPayload(
            id: $payload['id'] ?? $payload['event']['id'] ?? Str::random(16),
            type: $payload['event']['type'] ?? $payload['type'] ?? 'unknown',
            driver: $this->getName(),
            data: $payload['event']['data'] ?? $payload['data'] ?? [],
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
        $this->log('info', 'Crypto webhook received', ['type' => $payload->type, 'id' => $payload->id]);

        return [
            'handled' => true,
            'type' => $payload->type,
        ];
    }

    // ========================================
    // Helpers
    // ========================================

    protected function mapCoinbaseStatus(string $status): PaymentStatus
    {
        return match (mb_strtoupper($status)) {
            'COMPLETED', 'RESOLVED' => PaymentStatus::Succeeded,
            'NEW', 'PENDING' => PaymentStatus::Pending,
            'UNRESOLVED' => PaymentStatus::RequiresAction,
            'EXPIRED' => PaymentStatus::Expired,
            'CANCELED' => PaymentStatus::Canceled,
            default => PaymentStatus::Failed,
        };
    }
}

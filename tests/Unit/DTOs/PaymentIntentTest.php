<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Tests\Unit\DTOs;

use DateTimeImmutable;
use LaravelPlus\PaymentGateway\DTOs\PaymentIntent;
use LaravelPlus\PaymentGateway\Enums\PaymentStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PaymentIntentTest extends TestCase
{
    #[Test]
    public function it_creates_from_array_and_round_trips(): void
    {
        $data = [
            'id' => 'pi_123',
            'client_secret' => 'pi_123_secret_abc',
            'status' => 'pending',
            'amount' => 2500,
            'currency' => 'usd',
            'driver' => 'stripe',
            'customer_id' => 'cus_456',
            'return_url' => 'https://example.com/return',
            'cancel_url' => 'https://example.com/cancel',
            'expires_at' => '2030-12-31T23:59:59+00:00',
            'metadata' => ['order_id' => 'ord_789'],
        ];

        $intent = PaymentIntent::fromArray($data);

        $this->assertSame('pi_123', $intent->id);
        $this->assertSame('pi_123_secret_abc', $intent->clientSecret);
        $this->assertSame(PaymentStatus::Pending, $intent->status);
        $this->assertSame(2500, $intent->amount);
        $this->assertSame('USD', $intent->currency);
        $this->assertSame('stripe', $intent->driver);
        $this->assertSame('cus_456', $intent->customerId);
        $this->assertSame('https://example.com/return', $intent->returnUrl);
        $this->assertSame('https://example.com/cancel', $intent->cancelUrl);
        $this->assertNotNull($intent->expiresAt);
        $this->assertSame(['order_id' => 'ord_789'], $intent->metadata);

        $array = $intent->toArray();
        $roundTripped = PaymentIntent::fromArray($array);

        $this->assertSame($intent->id, $roundTripped->id);
        $this->assertSame($intent->clientSecret, $roundTripped->clientSecret);
        $this->assertSame($intent->status, $roundTripped->status);
        $this->assertSame($intent->amount, $roundTripped->amount);
        $this->assertSame($intent->currency, $roundTripped->currency);
    }

    #[Test]
    public function to_array_includes_all_fields(): void
    {
        $intent = new PaymentIntent(
            id: 'pi_1',
            clientSecret: 'secret_1',
            status: PaymentStatus::Succeeded,
            amount: 1000,
            currency: 'USD',
            driver: 'stripe',
        );

        $array = $intent->toArray();

        $this->assertSame('pi_1', $array['id']);
        $this->assertSame('secret_1', $array['client_secret']);
        $this->assertSame('succeeded', $array['status']);
        $this->assertSame(1000, $array['amount']);
        $this->assertSame(10.0, $array['amount_decimal']);
        $this->assertSame('USD', $array['currency']);
        $this->assertSame('stripe', $array['driver']);
        $this->assertNull($array['customer_id']);
        $this->assertNull($array['return_url']);
        $this->assertNull($array['cancel_url']);
        $this->assertNull($array['expires_at']);
        $this->assertSame([], $array['metadata']);
    }

    #[Test]
    public function get_amount_decimal_converts_cents_to_decimal(): void
    {
        $intent = new PaymentIntent(
            id: 'pi_1',
            clientSecret: 'secret',
            status: PaymentStatus::Pending,
            amount: 1050,
            currency: 'USD',
            driver: 'stripe',
        );

        $this->assertSame(10.5, $intent->getAmountDecimal());
    }

    #[Test]
    public function get_amount_decimal_handles_zero(): void
    {
        $intent = new PaymentIntent(
            id: 'pi_1',
            clientSecret: 'secret',
            status: PaymentStatus::Pending,
            amount: 0,
            currency: 'USD',
            driver: 'stripe',
        );

        $this->assertSame(0.0, $intent->getAmountDecimal());
    }

    #[Test]
    public function is_expired_returns_true_for_past_date(): void
    {
        $intent = new PaymentIntent(
            id: 'pi_1',
            clientSecret: 'secret',
            status: PaymentStatus::Pending,
            amount: 1000,
            currency: 'USD',
            driver: 'stripe',
            expiresAt: new DateTimeImmutable('2020-01-01T00:00:00+00:00'),
        );

        $this->assertTrue($intent->isExpired());
    }

    #[Test]
    public function is_expired_returns_false_for_future_date(): void
    {
        $intent = new PaymentIntent(
            id: 'pi_1',
            clientSecret: 'secret',
            status: PaymentStatus::Pending,
            amount: 1000,
            currency: 'USD',
            driver: 'stripe',
            expiresAt: new DateTimeImmutable('2099-12-31T23:59:59+00:00'),
        );

        $this->assertFalse($intent->isExpired());
    }

    #[Test]
    public function is_expired_returns_false_when_expires_at_is_null(): void
    {
        $intent = new PaymentIntent(
            id: 'pi_1',
            clientSecret: 'secret',
            status: PaymentStatus::Pending,
            amount: 1000,
            currency: 'USD',
            driver: 'stripe',
        );

        $this->assertFalse($intent->isExpired());
    }

    #[Test]
    public function status_is_parsed_from_string(): void
    {
        $intent = PaymentIntent::fromArray([
            'id' => 'pi_1',
            'client_secret' => 'secret',
            'status' => 'succeeded',
            'amount' => 500,
            'currency' => 'eur',
            'driver' => 'stripe',
        ]);

        $this->assertSame(PaymentStatus::Succeeded, $intent->status);
    }

    #[Test]
    public function status_accepts_enum_directly(): void
    {
        $intent = PaymentIntent::fromArray([
            'id' => 'pi_1',
            'client_secret' => 'secret',
            'status' => PaymentStatus::Failed,
            'amount' => 500,
            'currency' => 'eur',
            'driver' => 'stripe',
        ]);

        $this->assertSame(PaymentStatus::Failed, $intent->status);
    }

    #[Test]
    public function currency_is_uppercased(): void
    {
        $intent = PaymentIntent::fromArray([
            'id' => 'pi_1',
            'client_secret' => 'secret',
            'status' => 'pending',
            'amount' => 100,
            'currency' => 'gbp',
            'driver' => 'stripe',
        ]);

        $this->assertSame('GBP', $intent->currency);
    }
}

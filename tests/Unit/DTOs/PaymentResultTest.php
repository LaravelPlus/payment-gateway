<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Tests\Unit\DTOs;

use LaravelPlus\PaymentGateway\DTOs\PaymentResult;
use LaravelPlus\PaymentGateway\Enums\PaymentStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PaymentResultTest extends TestCase
{
    #[Test]
    public function it_creates_from_array_and_round_trips(): void
    {
        $data = [
            'transaction_id' => 'txn_abc',
            'status' => 'succeeded',
            'amount' => 4999,
            'currency' => 'usd',
            'driver' => 'stripe',
            'payment_method_id' => 'pm_123',
            'customer_id' => 'cus_456',
            'failure_code' => null,
            'failure_message' => null,
            'receipt_url' => 'https://example.com/receipt',
            'metadata' => ['invoice' => 'inv_001'],
        ];

        $result = PaymentResult::fromArray($data);

        $this->assertSame('txn_abc', $result->transactionId);
        $this->assertSame(PaymentStatus::Succeeded, $result->status);
        $this->assertSame(4999, $result->amount);
        $this->assertSame('USD', $result->currency);
        $this->assertSame('stripe', $result->driver);
        $this->assertSame('pm_123', $result->paymentMethodId);
        $this->assertSame('cus_456', $result->customerId);
        $this->assertNull($result->failureCode);
        $this->assertNull($result->failureMessage);
        $this->assertSame('https://example.com/receipt', $result->receiptUrl);
        $this->assertSame(['invoice' => 'inv_001'], $result->metadata);

        $array = $result->toArray();
        $roundTripped = PaymentResult::fromArray($array);

        $this->assertSame($result->transactionId, $roundTripped->transactionId);
        $this->assertSame($result->status, $roundTripped->status);
        $this->assertSame($result->amount, $roundTripped->amount);
        $this->assertSame($result->currency, $roundTripped->currency);
    }

    #[Test]
    public function to_array_includes_all_fields(): void
    {
        $result = new PaymentResult(
            transactionId: 'txn_1',
            status: PaymentStatus::Failed,
            amount: 500,
            currency: 'EUR',
            driver: 'paypal',
            failureCode: 'card_declined',
            failureMessage: 'Your card was declined',
        );

        $array = $result->toArray();

        $this->assertSame('txn_1', $array['transaction_id']);
        $this->assertSame('failed', $array['status']);
        $this->assertSame(500, $array['amount']);
        $this->assertSame(5.0, $array['amount_decimal']);
        $this->assertSame('EUR', $array['currency']);
        $this->assertSame('paypal', $array['driver']);
        $this->assertSame('card_declined', $array['failure_code']);
        $this->assertSame('Your card was declined', $array['failure_message']);
    }

    #[Test]
    public function is_successful_delegates_to_status_enum(): void
    {
        $result = new PaymentResult(
            transactionId: 'txn_1',
            status: PaymentStatus::Succeeded,
            amount: 1000,
            currency: 'USD',
            driver: 'stripe',
        );

        $this->assertTrue($result->isSuccessful());
        $this->assertFalse($result->isPending());
        $this->assertFalse($result->isFailed());
    }

    #[Test]
    public function is_pending_delegates_to_status_enum(): void
    {
        $result = new PaymentResult(
            transactionId: 'txn_1',
            status: PaymentStatus::Pending,
            amount: 1000,
            currency: 'USD',
            driver: 'stripe',
        );

        $this->assertTrue($result->isPending());
        $this->assertFalse($result->isSuccessful());
        $this->assertFalse($result->isFailed());
    }

    #[Test]
    public function is_failed_delegates_to_status_enum(): void
    {
        $result = new PaymentResult(
            transactionId: 'txn_1',
            status: PaymentStatus::Failed,
            amount: 1000,
            currency: 'USD',
            driver: 'stripe',
        );

        $this->assertTrue($result->isFailed());
        $this->assertFalse($result->isSuccessful());
        $this->assertFalse($result->isPending());
    }

    #[Test]
    public function get_amount_decimal_converts_cents_to_decimal(): void
    {
        $result = new PaymentResult(
            transactionId: 'txn_1',
            status: PaymentStatus::Succeeded,
            amount: 1050,
            currency: 'USD',
            driver: 'stripe',
        );

        $this->assertSame(10.5, $result->getAmountDecimal());
    }

    #[Test]
    public function get_amount_decimal_handles_zero(): void
    {
        $result = new PaymentResult(
            transactionId: 'txn_1',
            status: PaymentStatus::Succeeded,
            amount: 0,
            currency: 'USD',
            driver: 'stripe',
        );

        $this->assertSame(0.0, $result->getAmountDecimal());
    }

    #[Test]
    public function get_formatted_amount_returns_currency_string(): void
    {
        $result = new PaymentResult(
            transactionId: 'txn_1',
            status: PaymentStatus::Succeeded,
            amount: 1050,
            currency: 'USD',
            driver: 'stripe',
        );

        $formatted = $result->getFormattedAmount();

        $this->assertStringContainsString('10', $formatted);
        $this->assertStringContainsString('50', $formatted);
    }

    #[Test]
    public function status_is_parsed_from_string(): void
    {
        $result = PaymentResult::fromArray([
            'transaction_id' => 'txn_1',
            'status' => 'requires_action',
            'amount' => 100,
            'currency' => 'usd',
            'driver' => 'stripe',
        ]);

        $this->assertSame(PaymentStatus::RequiresAction, $result->status);
    }

    #[Test]
    public function status_accepts_enum_directly(): void
    {
        $result = PaymentResult::fromArray([
            'transaction_id' => 'txn_1',
            'status' => PaymentStatus::Refunded,
            'amount' => 100,
            'currency' => 'usd',
            'driver' => 'stripe',
        ]);

        $this->assertSame(PaymentStatus::Refunded, $result->status);
    }

    #[Test]
    public function currency_is_uppercased(): void
    {
        $result = PaymentResult::fromArray([
            'transaction_id' => 'txn_1',
            'status' => 'succeeded',
            'amount' => 100,
            'currency' => 'gbp',
            'driver' => 'stripe',
        ]);

        $this->assertSame('GBP', $result->currency);
    }
}

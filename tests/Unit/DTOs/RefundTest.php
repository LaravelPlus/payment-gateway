<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Tests\Unit\DTOs;

use DateTimeInterface;
use LaravelPlus\PaymentGateway\DTOs\Refund;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RefundTest extends TestCase
{
    #[Test]
    public function it_creates_from_array_and_round_trips(): void
    {
        $data = [
            'id' => 'ref_123',
            'transaction_id' => 'txn_456',
            'status' => 'succeeded',
            'amount' => 2500,
            'currency' => 'usd',
            'driver' => 'stripe',
            'reason' => 'customer_request',
            'failure_reason' => null,
            'created_at' => '2025-06-15T10:30:00+00:00',
            'metadata' => ['note' => 'Refund requested by customer'],
        ];

        $refund = Refund::fromArray($data);

        $this->assertSame('ref_123', $refund->id);
        $this->assertSame('txn_456', $refund->transactionId);
        $this->assertSame('succeeded', $refund->status);
        $this->assertSame(2500, $refund->amount);
        $this->assertSame('USD', $refund->currency);
        $this->assertSame('stripe', $refund->driver);
        $this->assertSame('customer_request', $refund->reason);
        $this->assertNull($refund->failureReason);
        $this->assertInstanceOf(DateTimeInterface::class, $refund->createdAt);
        $this->assertSame(['note' => 'Refund requested by customer'], $refund->metadata);

        $array = $refund->toArray();
        $roundTripped = Refund::fromArray($array);

        $this->assertSame($refund->id, $roundTripped->id);
        $this->assertSame($refund->transactionId, $roundTripped->transactionId);
        $this->assertSame($refund->status, $roundTripped->status);
        $this->assertSame($refund->amount, $roundTripped->amount);
        $this->assertSame($refund->currency, $roundTripped->currency);
    }

    #[Test]
    public function to_array_includes_all_fields(): void
    {
        $refund = new Refund(
            id: 'ref_1',
            transactionId: 'txn_1',
            status: 'pending',
            amount: 750,
            currency: 'EUR',
            driver: 'paypal',
            reason: 'duplicate',
            failureReason: 'insufficient_funds',
        );

        $array = $refund->toArray();

        $this->assertSame('ref_1', $array['id']);
        $this->assertSame('txn_1', $array['transaction_id']);
        $this->assertSame('pending', $array['status']);
        $this->assertSame(750, $array['amount']);
        $this->assertSame(7.5, $array['amount_decimal']);
        $this->assertSame('EUR', $array['currency']);
        $this->assertSame('paypal', $array['driver']);
        $this->assertSame('duplicate', $array['reason']);
        $this->assertSame('insufficient_funds', $array['failure_reason']);
        $this->assertNull($array['created_at']);
        $this->assertSame([], $array['metadata']);
    }

    #[Test]
    public function is_successful_returns_true_for_succeeded_status(): void
    {
        $refund = new Refund(
            id: 'ref_1',
            transactionId: 'txn_1',
            status: 'succeeded',
            amount: 1000,
            currency: 'USD',
            driver: 'stripe',
        );

        $this->assertTrue($refund->isSuccessful());
        $this->assertFalse($refund->isPending());
        $this->assertFalse($refund->isFailed());
    }

    #[Test]
    public function is_pending_returns_true_for_pending_status(): void
    {
        $refund = new Refund(
            id: 'ref_1',
            transactionId: 'txn_1',
            status: 'pending',
            amount: 1000,
            currency: 'USD',
            driver: 'stripe',
        );

        $this->assertTrue($refund->isPending());
        $this->assertFalse($refund->isSuccessful());
        $this->assertFalse($refund->isFailed());
    }

    #[Test]
    public function is_failed_returns_true_for_failed_status(): void
    {
        $refund = new Refund(
            id: 'ref_1',
            transactionId: 'txn_1',
            status: 'failed',
            amount: 1000,
            currency: 'USD',
            driver: 'stripe',
        );

        $this->assertTrue($refund->isFailed());
        $this->assertFalse($refund->isSuccessful());
        $this->assertFalse($refund->isPending());
    }

    #[Test]
    public function get_amount_decimal_converts_cents_to_decimal(): void
    {
        $refund = new Refund(
            id: 'ref_1',
            transactionId: 'txn_1',
            status: 'succeeded',
            amount: 1050,
            currency: 'USD',
            driver: 'stripe',
        );

        $this->assertSame(10.5, $refund->getAmountDecimal());
    }

    #[Test]
    public function get_amount_decimal_handles_zero(): void
    {
        $refund = new Refund(
            id: 'ref_1',
            transactionId: 'txn_1',
            status: 'succeeded',
            amount: 0,
            currency: 'USD',
            driver: 'stripe',
        );

        $this->assertSame(0.0, $refund->getAmountDecimal());
    }

    #[Test]
    public function currency_is_uppercased(): void
    {
        $refund = Refund::fromArray([
            'id' => 'ref_1',
            'transaction_id' => 'txn_1',
            'status' => 'pending',
            'amount' => 100,
            'currency' => 'gbp',
            'driver' => 'stripe',
        ]);

        $this->assertSame('GBP', $refund->currency);
    }

    #[Test]
    public function from_array_handles_missing_optional_fields(): void
    {
        $refund = Refund::fromArray([
            'id' => 'ref_1',
            'transaction_id' => 'txn_1',
            'status' => 'succeeded',
            'amount' => 500,
            'currency' => 'usd',
            'driver' => 'stripe',
        ]);

        $this->assertNull($refund->reason);
        $this->assertNull($refund->failureReason);
        $this->assertNull($refund->createdAt);
        $this->assertSame([], $refund->metadata);
        $this->assertSame([], $refund->raw);
    }
}

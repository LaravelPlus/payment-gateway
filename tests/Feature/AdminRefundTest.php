<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Tests\Feature;

use LaravelPlus\PaymentGateway\Contracts\SupportsRefunds;
use LaravelPlus\PaymentGateway\DTOs\Refund;
use LaravelPlus\PaymentGateway\Facades\Payment;
use LaravelPlus\PaymentGateway\Http\Controllers\Admin\TransactionController;
use LaravelPlus\PaymentGateway\Http\Requests\Admin\RefundTransactionRequest;
use LaravelPlus\PaymentGateway\Models\Transaction;
use LaravelPlus\PaymentGateway\Tests\TestCase;
use Mockery;

class AdminRefundTest extends TestCase
{
    private function refundWith(array $input, SupportsRefunds $gateway): void
    {
        Payment::swap(new class($gateway) {
            public function __construct(private object $gateway) {}

            public function driver(string $name): object
            {
                return $this->gateway;
            }
        });

        $tx = Transaction::create(['driver' => 'stripe', 'provider_id' => 'ch_1', 'amount' => 1000, 'currency' => 'USD', 'status' => 'succeeded']);

        (new TransactionController())->refund(RefundTransactionRequest::create('/x', 'POST', $input), $tx);
    }

    private function dto(): Refund
    {
        return new Refund('re_1', 'ch_1', 'succeeded', 500, 'USD', 'stripe');
    }

    public function test_partial_amount_uses_partial_refund(): void
    {
        $gateway = Mockery::mock(SupportsRefunds::class);
        $gateway->shouldReceive('partialRefund')->once()->with('ch_1', 500, 'why')->andReturn($this->dto());
        $gateway->shouldNotReceive('refund');

        $this->refundWith(['amount' => 500, 'reason' => 'why'], $gateway);
    }

    public function test_no_amount_uses_full_refund(): void
    {
        $gateway = Mockery::mock(SupportsRefunds::class);
        $gateway->shouldReceive('refund')->once()->with('ch_1', null)->andReturn($this->dto());

        $this->refundWith([], $gateway);
    }
}

<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Tests\Unit\Drivers;

use Illuminate\Support\Facades\Http;
use LaravelPlus\PaymentGateway\Drivers\PayPalGateway;
use LaravelPlus\PaymentGateway\DTOs\Customer;
use LaravelPlus\PaymentGateway\Enums\PaymentStatus;
use LaravelPlus\PaymentGateway\Enums\SubscriptionStatus;
use LaravelPlus\PaymentGateway\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;

class PayPalGatewayTest extends TestCase
{
    private PayPalGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new PayPalGateway([
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'mode' => 'sandbox',
            'webhook_id' => 'test-webhook-id',
        ]);
    }

    #[Test]
    public function testGetName(): void
    {
        $this->assertSame('paypal', $this->gateway->getName());
    }

    #[Test]
    public function testGetDisplayName(): void
    {
        $this->assertSame('PayPal', $this->gateway->getDisplayName());
    }

    #[Test]
    public function testIsAvailableWithCredentials(): void
    {
        $this->assertTrue($this->gateway->isAvailable());
    }

    #[Test]
    public function testIsAvailableWithoutCredentials(): void
    {
        $gateway = new PayPalGateway([]);

        $this->assertFalse($gateway->isAvailable());
    }

    #[Test]
    public function testGetSupportedCurrencies(): void
    {
        $currencies = $this->gateway->getSupportedCurrencies();

        $this->assertContains('USD', $currencies);
        $this->assertContains('EUR', $currencies);
        $this->assertContains('GBP', $currencies);
    }

    #[Test]
    public function testCreatePaymentIntent(): void
    {
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/v2/checkout/orders' => Http::response([
                'id' => 'ORDER-123',
                'status' => 'CREATED',
                'links' => [
                    ['rel' => 'approve', 'href' => 'https://paypal.com/approve/ORDER-123'],
                ],
            ], 200),
        ]);

        $intent = $this->gateway->createPaymentIntent(1050, 'USD');

        $this->assertSame('ORDER-123', $intent->id);
        $this->assertSame(PaymentStatus::Pending, $intent->status);
        $this->assertSame(1050, $intent->amount);
        $this->assertSame('USD', $intent->currency);
        $this->assertSame('paypal', $intent->driver);
    }

    #[Test]
    public function testCharge(): void
    {
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/v2/checkout/orders/ORDER-123/capture' => Http::response([
                'id' => 'CAPTURE-456',
                'status' => 'COMPLETED',
            ], 200),
        ]);

        $result = $this->gateway->charge(1050, 'USD', 'ORDER-123');

        $this->assertSame('CAPTURE-456', $result->transactionId);
        $this->assertSame(PaymentStatus::Succeeded, $result->status);
        $this->assertSame(1050, $result->amount);
        $this->assertSame('USD', $result->currency);
        $this->assertSame('paypal', $result->driver);
    }

    #[Test]
    public function testGetPayment(): void
    {
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/v2/checkout/orders/ORDER-123' => Http::response([
                'id' => 'ORDER-123',
                'status' => 'COMPLETED',
                'purchase_units' => [[
                    'amount' => [
                        'value' => '10.50',
                        'currency_code' => 'USD',
                    ],
                ]],
            ], 200),
        ]);

        $result = $this->gateway->getPayment('ORDER-123');

        $this->assertNotNull($result);
        $this->assertSame('ORDER-123', $result->transactionId);
        $this->assertSame(PaymentStatus::Succeeded, $result->status);
        $this->assertSame(1050, $result->amount);
        $this->assertSame('USD', $result->currency);
    }

    #[Test]
    public function testGetPaymentReturnsNullOnFailure(): void
    {
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/v2/checkout/orders/ORDER-INVALID' => Http::response([], 404),
        ]);

        $result = $this->gateway->getPayment('ORDER-INVALID');

        $this->assertNull($result);
    }

    #[Test]
    public function testCancel(): void
    {
        $this->assertTrue($this->gateway->cancel('ORDER-123'));
    }

    #[Test]
    public function testRefund(): void
    {
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/v2/checkout/orders/ORDER-123' => Http::response([
                'id' => 'ORDER-123',
                'purchase_units' => [[
                    'payments' => [
                        'captures' => [['id' => 'CAPTURE-456']],
                    ],
                ]],
            ], 200),
            '*/v2/payments/captures/CAPTURE-456/refund' => Http::response([
                'id' => 'REFUND-789',
                'status' => 'COMPLETED',
                'amount' => [
                    'value' => '10.50',
                    'currency_code' => 'USD',
                ],
            ], 200),
        ]);

        $refund = $this->gateway->refund('ORDER-123', 'Customer request');

        $this->assertSame('REFUND-789', $refund->id);
        $this->assertSame('ORDER-123', $refund->transactionId);
        $this->assertSame('completed', $refund->status);
        $this->assertSame(1050, $refund->amount);
        $this->assertSame('USD', $refund->currency);
        $this->assertSame('paypal', $refund->driver);
        $this->assertSame('Customer request', $refund->reason);
    }

    #[Test]
    public function testPartialRefund(): void
    {
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/v2/checkout/orders/ORDER-123' => Http::response([
                'id' => 'ORDER-123',
                'purchase_units' => [[
                    'amount' => ['currency_code' => 'USD'],
                    'payments' => [
                        'captures' => [['id' => 'CAPTURE-456']],
                    ],
                ]],
            ], 200),
            '*/v2/payments/captures/CAPTURE-456/refund' => Http::response([
                'id' => 'REFUND-PARTIAL',
                'status' => 'COMPLETED',
                'amount' => [
                    'value' => '5.00',
                    'currency_code' => 'USD',
                ],
            ], 200),
        ]);

        $refund = $this->gateway->partialRefund('ORDER-123', 500, 'Partial refund');

        $this->assertSame('REFUND-PARTIAL', $refund->id);
        $this->assertSame(500, $refund->amount);
        $this->assertSame('USD', $refund->currency);
        $this->assertSame('Partial refund', $refund->reason);
    }

    #[Test]
    public function testGetRefundReturnsNull(): void
    {
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/v2/payments/refunds/REFUND-INVALID' => Http::response([], 404),
        ]);

        $result = $this->gateway->getRefund('REFUND-INVALID');

        $this->assertNull($result);
    }

    #[Test]
    public function testCreateCustomer(): void
    {
        $customer = $this->gateway->createCustomer('test@example.com', 'John Doe');

        $this->assertStringStartsWith('paypal_', $customer->id);
        $this->assertSame('test@example.com', $customer->email);
        $this->assertSame('John Doe', $customer->name);
    }

    #[Test]
    public function testGetCustomer(): void
    {
        $result = $this->gateway->getCustomer('paypal_nonexistent');

        $this->assertNull($result);
    }

    #[Test]
    public function testDeleteCustomer(): void
    {
        $this->assertTrue($this->gateway->deleteCustomer('paypal_123'));
    }

    #[Test]
    public function testCreatePlan(): void
    {
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/v1/catalogs/products' => Http::response([
                'id' => 'PROD-001',
                'name' => 'Pro Plan',
            ], 200),
            '*/v1/billing/plans' => Http::response([
                'id' => 'P-PLAN-001',
                'product_id' => 'PROD-001',
                'name' => 'Pro Plan',
                'status' => 'ACTIVE',
            ], 200),
        ]);

        $plan = $this->gateway->createPlan('Pro Plan', 2999, 'USD', 'MONTH');

        $this->assertSame('P-PLAN-001', $plan->id);
        $this->assertSame('PROD-001', $plan->productId);
        $this->assertSame('Pro Plan', $plan->name);
        $this->assertSame(2999, $plan->amount);
        $this->assertSame('USD', $plan->currency);
        $this->assertSame('MONTH', $plan->interval);
        $this->assertSame('paypal', $plan->driver);
    }

    #[Test]
    public function testCreateSubscription(): void
    {
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/v1/billing/subscriptions' => Http::response([
                'id' => 'I-SUB-001',
                'status' => 'APPROVAL_PENDING',
                'links' => [
                    ['rel' => 'approve', 'href' => 'https://paypal.com/approve/I-SUB-001'],
                ],
            ], 200),
        ]);

        $customer = new Customer(
            id: 'cust-123',
            email: 'test@example.com',
            name: 'John Doe',
        );

        $subscription = $this->gateway->createSubscription($customer, 'P-PLAN-001', 'pm-123');

        $this->assertSame('I-SUB-001', $subscription->id);
        $this->assertSame('cust-123', $subscription->customerId);
        $this->assertSame('P-PLAN-001', $subscription->planId);
        $this->assertSame(SubscriptionStatus::Incomplete, $subscription->status);
        $this->assertSame('paypal', $subscription->driver);
    }

    #[Test]
    public function testCancelSubscription(): void
    {
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'test-token'], 200),
            '*/v1/billing/subscriptions/I-SUB-001/cancel' => Http::response([], 204),
        ]);

        $result = $this->gateway->cancelSubscription('I-SUB-001');

        $this->assertTrue($result);
    }

    #[Test]
    public function testMapPayPalStatus(): void
    {
        $method = new ReflectionMethod(PayPalGateway::class, 'mapPayPalStatus');

        $this->assertSame(PaymentStatus::Succeeded, $method->invoke($this->gateway, 'completed'));
        $this->assertSame(PaymentStatus::Pending, $method->invoke($this->gateway, 'created'));
        $this->assertSame(PaymentStatus::Canceled, $method->invoke($this->gateway, 'voided'));
        $this->assertSame(PaymentStatus::Failed, $method->invoke($this->gateway, 'unknown'));
    }
}

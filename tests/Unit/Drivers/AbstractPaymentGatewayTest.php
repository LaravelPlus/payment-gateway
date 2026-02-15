<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Tests\Unit\Drivers;

use LaravelPlus\PaymentGateway\Drivers\AbstractPaymentGateway;
use LaravelPlus\PaymentGateway\DTOs\Customer;
use LaravelPlus\PaymentGateway\DTOs\PaymentIntent;
use LaravelPlus\PaymentGateway\DTOs\PaymentResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AbstractPaymentGatewayTest extends TestCase
{
    private AbstractPaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new class(['key' => 'value'], 'USD') extends AbstractPaymentGateway {
            public function createPaymentIntent(int $amount, string $currency, ?Customer $customer = null, array $metadata = []): PaymentIntent
            {
                throw new RuntimeException('Not implemented');
            }

            public function charge(int $amount, string $currency, string $paymentMethodId, array $options = []): PaymentResult
            {
                throw new RuntimeException('Not implemented');
            }

            public function getPayment(string $transactionId): ?PaymentResult
            {
                return null;
            }

            public function cancel(string $transactionId): bool
            {
                return true;
            }

            public function getName(): string
            {
                return 'test';
            }

            public function getDisplayName(): string
            {
                return 'Test Gateway';
            }

            public function isAvailable(): bool
            {
                return true;
            }

            /**
             * @return array<string>
             */
            public function getSupportedCurrencies(): array
            {
                return ['USD', 'EUR', 'GBP'];
            }

            public function testEnsureCents(int|float $amount): int
            {
                return $this->ensureCents($amount);
            }

            public function testFormatAmount(int $amountInCents, string $currency): string
            {
                return $this->formatAmount($amountInCents, $currency);
            }

            public function testToDecimalString(int $amountInCents): string
            {
                return $this->toDecimalString($amountInCents);
            }

            public function testGetConfig(string $key, mixed $default = null): mixed
            {
                return $this->getConfig($key, $default);
            }
        };
    }

    #[Test]
    public function testEnsureCentsWithInteger(): void
    {
        $result = $this->gateway->testEnsureCents(1000);

        $this->assertSame(1000, $result);
    }

    #[Test]
    public function testEnsureCentsWithFloat(): void
    {
        $result = $this->gateway->testEnsureCents(10.50);

        $this->assertSame(1050, $result);
    }

    #[Test]
    public function testFormatAmountReturnsCurrencyString(): void
    {
        $result = $this->gateway->testFormatAmount(1050, 'USD');

        $this->assertStringContainsString('10.50', $result);
    }

    #[Test]
    public function testToDecimalStringConvertsCorrectly(): void
    {
        $this->assertSame('10.50', $this->gateway->testToDecimalString(1050));
        $this->assertSame('1.00', $this->gateway->testToDecimalString(100));
        $this->assertSame('0.01', $this->gateway->testToDecimalString(1));
        $this->assertSame('0.00', $this->gateway->testToDecimalString(0));
    }

    #[Test]
    public function testSupportsCurrencyReturnsTrue(): void
    {
        $this->assertTrue($this->gateway->supportsCurrency('usd'));
    }

    #[Test]
    public function testSupportsCurrencyReturnsFalse(): void
    {
        $this->assertFalse($this->gateway->supportsCurrency('JPY'));
    }

    #[Test]
    public function testSetAndGetCurrency(): void
    {
        $this->gateway->setCurrency('eur');

        $this->assertSame('EUR', $this->gateway->getCurrency());
    }

    #[Test]
    public function testGetConfigReturnsValue(): void
    {
        $this->assertSame('value', $this->gateway->testGetConfig('key'));
        $this->assertSame('default', $this->gateway->testGetConfig('missing', 'default'));
    }
}

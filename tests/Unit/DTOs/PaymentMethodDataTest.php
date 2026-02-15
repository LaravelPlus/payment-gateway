<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Tests\Unit\DTOs;

use LaravelPlus\PaymentGateway\DTOs\PaymentMethodData;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PaymentMethodDataTest extends TestCase
{
    #[Test]
    public function it_creates_from_array(): void
    {
        $data = [
            'id' => 'pm_123',
            'type' => 'card',
            'driver' => 'stripe',
            'card_brand' => 'visa',
            'card_last_four' => '4242',
            'card_exp_month' => 12,
            'card_exp_year' => 2030,
            'is_default' => true,
        ];

        $method = PaymentMethodData::fromArray($data);

        $this->assertSame('pm_123', $method->id);
        $this->assertSame('card', $method->type);
        $this->assertSame('stripe', $method->driver);
        $this->assertSame('visa', $method->cardBrand);
        $this->assertSame('4242', $method->cardLastFour);
        $this->assertSame(12, $method->cardExpMonth);
        $this->assertSame(2030, $method->cardExpYear);
        $this->assertTrue($method->isDefault);
    }

    #[Test]
    public function to_array_includes_display_fields(): void
    {
        $method = new PaymentMethodData(
            id: 'pm_1',
            type: 'card',
            driver: 'stripe',
            cardBrand: 'mastercard',
            cardLastFour: '5678',
            cardExpMonth: 6,
            cardExpYear: 2028,
        );

        $array = $method->toArray();

        $this->assertSame('pm_1', $array['id']);
        $this->assertSame('card', $array['type']);
        $this->assertSame('stripe', $array['driver']);
        $this->assertSame('mastercard', $array['card_brand']);
        $this->assertSame('5678', $array['card_last_four']);
        $this->assertSame(6, $array['card_exp_month']);
        $this->assertSame(2028, $array['card_exp_year']);
        $this->assertArrayHasKey('display_name', $array);
        $this->assertArrayHasKey('expiry', $array);
    }

    #[Test]
    public function is_card_returns_true_for_card_type(): void
    {
        $method = new PaymentMethodData(id: 'pm_1', type: 'card', driver: 'stripe');

        $this->assertTrue($method->isCard());
    }

    #[Test]
    public function is_card_returns_false_for_non_card_type(): void
    {
        $method = new PaymentMethodData(id: 'pm_1', type: 'bank_account', driver: 'stripe');

        $this->assertFalse($method->isCard());
    }

    #[Test]
    public function is_expired_returns_true_for_past_expiry(): void
    {
        $method = new PaymentMethodData(
            id: 'pm_1',
            type: 'card',
            driver: 'stripe',
            cardExpMonth: 1,
            cardExpYear: 2020,
        );

        $this->assertTrue($method->isExpired());
    }

    #[Test]
    public function is_expired_returns_false_for_future_expiry(): void
    {
        $method = new PaymentMethodData(
            id: 'pm_1',
            type: 'card',
            driver: 'stripe',
            cardExpMonth: 12,
            cardExpYear: 2099,
        );

        $this->assertFalse($method->isExpired());
    }

    #[Test]
    public function is_expired_returns_false_for_non_card(): void
    {
        $method = new PaymentMethodData(
            id: 'pm_1',
            type: 'bank_account',
            driver: 'stripe',
        );

        $this->assertFalse($method->isExpired());
    }

    #[Test]
    public function is_expired_returns_false_when_card_has_no_expiry(): void
    {
        $method = new PaymentMethodData(
            id: 'pm_1',
            type: 'card',
            driver: 'stripe',
        );

        $this->assertFalse($method->isExpired());
    }

    #[Test]
    public function get_display_name_for_card(): void
    {
        $method = new PaymentMethodData(
            id: 'pm_1',
            type: 'card',
            driver: 'stripe',
            cardBrand: 'visa',
            cardLastFour: '4242',
        );

        $this->assertSame('Visa •••• 4242', $method->getDisplayName());
    }

    #[Test]
    public function get_display_name_for_card_without_brand(): void
    {
        $method = new PaymentMethodData(
            id: 'pm_1',
            type: 'card',
            driver: 'stripe',
            cardLastFour: '9999',
        );

        $this->assertSame('Card •••• 9999', $method->getDisplayName());
    }

    #[Test]
    public function get_display_name_for_bank_account(): void
    {
        $method = new PaymentMethodData(
            id: 'pm_1',
            type: 'bank_account',
            driver: 'stripe',
            bankName: 'Chase',
            bankLastFour: '1234',
        );

        $this->assertSame('Chase •••• 1234', $method->getDisplayName());
    }

    #[Test]
    public function get_display_name_for_bank_account_without_name(): void
    {
        $method = new PaymentMethodData(
            id: 'pm_1',
            type: 'bank_account',
            driver: 'stripe',
            bankLastFour: '5678',
        );

        $this->assertSame('Bank •••• 5678', $method->getDisplayName());
    }

    #[Test]
    public function get_display_name_for_paypal(): void
    {
        $method = new PaymentMethodData(
            id: 'pm_1',
            type: 'paypal',
            driver: 'paypal',
            paypalEmail: 'user@paypal.com',
        );

        $this->assertSame('PayPal (user@paypal.com)', $method->getDisplayName());
    }

    #[Test]
    public function get_display_name_for_crypto(): void
    {
        $method = new PaymentMethodData(
            id: 'pm_1',
            type: 'crypto',
            driver: 'coinbase',
            cryptoCurrency: 'btc',
        );

        $this->assertSame('BTC Wallet', $method->getDisplayName());
    }

    #[Test]
    public function get_display_name_for_crypto_without_currency(): void
    {
        $method = new PaymentMethodData(
            id: 'pm_1',
            type: 'crypto',
            driver: 'coinbase',
        );

        $this->assertSame('CRYPTO Wallet', $method->getDisplayName());
    }

    #[Test]
    public function get_display_name_for_unknown_type(): void
    {
        $method = new PaymentMethodData(
            id: 'pm_1',
            type: 'wire_transfer',
            driver: 'custom',
        );

        $this->assertSame('Wire_transfer', $method->getDisplayName());
    }

    #[Test]
    public function get_expiry_string_formats_correctly(): void
    {
        $method = new PaymentMethodData(
            id: 'pm_1',
            type: 'card',
            driver: 'stripe',
            cardExpMonth: 3,
            cardExpYear: 2028,
        );

        $this->assertSame('03/28', $method->getExpiryString());
    }

    #[Test]
    public function get_expiry_string_pads_single_digit_month(): void
    {
        $method = new PaymentMethodData(
            id: 'pm_1',
            type: 'card',
            driver: 'stripe',
            cardExpMonth: 1,
            cardExpYear: 2030,
        );

        $this->assertSame('01/30', $method->getExpiryString());
    }

    #[Test]
    public function get_expiry_string_returns_null_for_non_card(): void
    {
        $method = new PaymentMethodData(
            id: 'pm_1',
            type: 'bank_account',
            driver: 'stripe',
        );

        $this->assertNull($method->getExpiryString());
    }

    #[Test]
    public function get_expiry_string_returns_null_when_no_expiry_data(): void
    {
        $method = new PaymentMethodData(
            id: 'pm_1',
            type: 'card',
            driver: 'stripe',
        );

        $this->assertNull($method->getExpiryString());
    }

    #[Test]
    public function from_array_sets_bank_and_paypal_fields(): void
    {
        $data = [
            'id' => 'pm_bank',
            'type' => 'bank_account',
            'driver' => 'stripe',
            'bank_name' => 'Wells Fargo',
            'bank_last_four' => '9876',
        ];

        $method = PaymentMethodData::fromArray($data);

        $this->assertSame('Wells Fargo', $method->bankName);
        $this->assertSame('9876', $method->bankLastFour);

        $paypalData = [
            'id' => 'pm_paypal',
            'type' => 'paypal',
            'driver' => 'paypal',
            'paypal_email' => 'test@paypal.com',
        ];

        $paypalMethod = PaymentMethodData::fromArray($paypalData);

        $this->assertSame('test@paypal.com', $paypalMethod->paypalEmail);
    }

    #[Test]
    public function from_array_sets_crypto_fields(): void
    {
        $data = [
            'id' => 'pm_crypto',
            'type' => 'crypto',
            'driver' => 'coinbase',
            'crypto_currency' => 'eth',
            'crypto_address' => '0xabc123',
        ];

        $method = PaymentMethodData::fromArray($data);

        $this->assertSame('eth', $method->cryptoCurrency);
        $this->assertSame('0xabc123', $method->cryptoAddress);
    }

    #[Test]
    public function from_array_handles_billing_address(): void
    {
        $data = [
            'id' => 'pm_1',
            'type' => 'card',
            'driver' => 'stripe',
            'billing_address' => [
                'line1' => '123 Card St',
                'city' => 'Cardville',
                'postal_code' => '55555',
                'country' => 'US',
            ],
        ];

        $method = PaymentMethodData::fromArray($data);

        $this->assertNotNull($method->billingAddress);
        $this->assertSame('123 Card St', $method->billingAddress->line1);
    }

    #[Test]
    public function to_array_filters_null_values(): void
    {
        $method = new PaymentMethodData(
            id: 'pm_1',
            type: 'card',
            driver: 'stripe',
            cardBrand: 'visa',
            cardLastFour: '4242',
        );

        $array = $method->toArray();

        $this->assertArrayNotHasKey('bank_name', $array);
        $this->assertArrayNotHasKey('bank_last_four', $array);
        $this->assertArrayNotHasKey('paypal_email', $array);
        $this->assertArrayNotHasKey('crypto_currency', $array);
        $this->assertArrayNotHasKey('crypto_address', $array);
        $this->assertArrayNotHasKey('billing_address', $array);
    }
}

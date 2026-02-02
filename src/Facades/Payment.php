<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Facades;

use Illuminate\Support\Facades\Facade;
use Nejcc\PaymentGateway\Contracts\PaymentGatewayContract;
use Nejcc\PaymentGateway\DTOs\Customer;
use Nejcc\PaymentGateway\DTOs\PaymentIntent;
use Nejcc\PaymentGateway\DTOs\PaymentResult;
use Nejcc\PaymentGateway\PaymentGatewayManager;

/**
 * Payment Facade.
 *
 * @method static PaymentGatewayContract driver(?string $driver = null)
 * @method static PaymentGatewayContract stripe()
 * @method static PaymentGatewayContract paypal()
 * @method static PaymentGatewayContract crypto()
 * @method static PaymentGatewayContract bankTransfer()
 * @method static PaymentGatewayContract cashOnDelivery()
 * @method static PaymentGatewayContract fromRequest()
 * @method static string getDefaultDriver()
 * @method static array<string> getAvailableDrivers()
 * @method static string getName()
 * @method static string getDisplayName()
 * @method static bool isAvailable()
 * @method static array<string> getSupportedCurrencies()
 * @method static bool supportsCurrency(string $currency)
 * @method static PaymentIntent createPaymentIntent(int $amount, string $currency, ?Customer $customer = null, array $metadata = [])
 * @method static PaymentResult charge(int $amount, string $currency, string $paymentMethodId, array $options = [])
 * @method static PaymentResult|null getPayment(string $transactionId)
 * @method static bool cancel(string $transactionId)
 *
 * @see PaymentGatewayManager
 */
final class Payment extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'payment';
    }
}

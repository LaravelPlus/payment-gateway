<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway;

use Exception;
use Illuminate\Support\Manager;
use Nejcc\PaymentGateway\Contracts\PaymentGatewayContract;
use Nejcc\PaymentGateway\Drivers\BankTransferGateway;
use Nejcc\PaymentGateway\Drivers\CashOnDeliveryGateway;
use Nejcc\PaymentGateway\Drivers\CryptoGateway;
use Nejcc\PaymentGateway\Drivers\PayPalGateway;
use Nejcc\PaymentGateway\Drivers\StripeGateway;

/**
 * Payment Gateway Manager.
 *
 * Manages multiple payment gateway drivers using Laravel's Manager pattern.
 *
 * @method PaymentGatewayContract driver(?string $driver = null)
 */
final class PaymentGatewayManager extends Manager
{
    /**
     * Get the default driver name.
     */
    public function getDefaultDriver(): string
    {
        return $this->config->get('payment-gateway.default', 'stripe');
    }

    /**
     * Create a Stripe gateway driver.
     */
    protected function createStripeDriver(): PaymentGatewayContract
    {
        $config = $this->config->get('payment-gateway.drivers.stripe', []);

        return new StripeGateway($config);
    }

    /**
     * Create a PayPal gateway driver.
     */
    protected function createPaypalDriver(): PaymentGatewayContract
    {
        $config = $this->config->get('payment-gateway.drivers.paypal', []);

        return new PayPalGateway($config);
    }

    /**
     * Create a Crypto gateway driver.
     */
    protected function createCryptoDriver(): PaymentGatewayContract
    {
        $config = $this->config->get('payment-gateway.drivers.crypto', []);

        return new CryptoGateway($config);
    }

    /**
     * Create a Bank Transfer gateway driver.
     */
    protected function createBankTransferDriver(): PaymentGatewayContract
    {
        $config = $this->config->get('payment-gateway.drivers.bank_transfer', []);

        return new BankTransferGateway($config);
    }

    /**
     * Create a Cash on Delivery gateway driver.
     */
    protected function createCashOnDeliveryDriver(): PaymentGatewayContract
    {
        $config = $this->config->get('payment-gateway.drivers.cash_on_delivery', []);

        return new CashOnDeliveryGateway($config);
    }

    /**
     * Get all available drivers.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getAvailableDrivers(): array
    {
        $drivers = [];
        $configured = $this->config->get('payment-gateway.drivers', []);

        foreach ($configured as $name => $config) {
            $driver = $this->driver($name);
            if ($driver->isAvailable()) {
                $drivers[$name] = [
                    'name' => $driver->getName(),
                    'display_name' => $driver->getDisplayName(),
                    'currencies' => $driver->getSupportedCurrencies(),
                ];
            }
        }

        return $drivers;
    }

    /**
     * Check if a driver exists and is available.
     */
    public function hasDriver(string $driver): bool
    {
        try {
            return $this->driver($driver)->isAvailable();
        } catch (Exception) {
            return false;
        }
    }

    /**
     * Get driver for a specific payment method from request.
     */
    public function fromRequest(): PaymentGatewayContract
    {
        $method = request()->input('payment_method', $this->getDefaultDriver());

        return $this->driver($method);
    }
}

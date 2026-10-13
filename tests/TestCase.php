<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use LaravelPlus\PaymentGateway\PaymentGatewayServiceProvider;
use Orchestra\Testbench\Attributes\WithMigration;
use Orchestra\Testbench\TestCase as BaseTestCase;

// The package's migrations alter `users`, which a host app always has.
#[WithMigration]
abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [PaymentGatewayServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('payment-gateway.default', 'stripe');
        $app['config']->set('payment-gateway.currency', 'USD');
    }
}

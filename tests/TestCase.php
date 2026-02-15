<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('payment-gateway.default', 'stripe');
        $this->app['config']->set('payment-gateway.currency', 'USD');
    }
}

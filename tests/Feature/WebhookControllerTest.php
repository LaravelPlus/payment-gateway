<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Tests\Feature;

use Illuminate\Support\Facades\Event;
use LaravelPlus\PaymentGateway\Contracts\SupportsWebhooks;
use LaravelPlus\PaymentGateway\DTOs\WebhookPayload;
use LaravelPlus\PaymentGateway\Events\WebhookHandleFailed;
use LaravelPlus\PaymentGateway\Facades\Payment;
use LaravelPlus\PaymentGateway\Tests\TestCase;
use Mockery;
use TypeError;

class WebhookControllerTest extends TestCase
{
    public function test_error_thrown_by_handler_returns_json_500_and_fires_failure_event(): void
    {
        Event::fake([WebhookHandleFailed::class]);

        $gateway = Mockery::mock(SupportsWebhooks::class);
        $gateway->shouldReceive('verifyWebhookSignature')->andReturn(true);
        $gateway->shouldReceive('parseWebhook')->andReturn(new WebhookPayload('evt_1', 'x', 'stripe', []));
        $gateway->shouldReceive('handleWebhook')->andThrow(new TypeError('boom'));
        Payment::swap(new class($gateway) {
            public function __construct(private object $gateway) {}

            public function driver(string $name): object
            {
                return $this->gateway;
            }
        });

        $this->postJson('/webhooks/payment/stripe', ['a' => 1])
            ->assertStatus(500)
            ->assertJson(['error' => 'Webhook processing failed']);

        Event::assertDispatched(WebhookHandleFailed::class);
    }
}

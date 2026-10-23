<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Tests\Feature;

use LaravelPlus\PaymentGateway\DTOs\WebhookPayload;
use LaravelPlus\PaymentGateway\Events\PaymentWebhookReceived;
use LaravelPlus\PaymentGateway\Listeners\HandleStripeWebhook;
use LaravelPlus\PaymentGateway\Models\Subscription;
use LaravelPlus\PaymentGateway\Models\Transaction;
use LaravelPlus\PaymentGateway\Tests\TestCase;

class StripeWebhookListenerTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('payment-gateway.billable_model', \Illuminate\Foundation\Auth\User::class);
    }

    private function dispatch(string $type, array $object): void
    {
        // StripeGateway::parseWebhook already unwraps data.object, so the listener gets the bare object.
        app(HandleStripeWebhook::class)->handle(new PaymentWebhookReceived(
            new WebhookPayload('evt_1', $type, 'stripe', $object)
        ));
    }

    public function test_payment_failed_updates_transaction_from_unwrapped_stripe_object(): void
    {
        $tx = Transaction::create(['driver' => 'stripe', 'provider_id' => 'pi_1', 'amount' => 1000, 'currency' => 'USD', 'status' => 'pending']);

        $this->dispatch('payment_intent.payment_failed', [
            'id' => 'pi_1',
            'object' => 'payment_intent',
            'last_payment_error' => ['message' => 'Card declined'],
        ]);

        $tx->refresh();
        $this->assertSame('failed', $tx->status->value ?? $tx->status);
        $this->assertSame('Card declined', $tx->failure_message);
    }

    public function test_charge_failed_updates_transaction(): void
    {
        $tx = Transaction::create(['driver' => 'stripe', 'provider_id' => 'ch_1', 'amount' => 1000, 'currency' => 'USD', 'status' => 'pending']);

        $this->dispatch('charge.failed', ['id' => 'ch_1', 'object' => 'charge', 'failure_message' => 'Nope']);

        $this->assertSame('Nope', $tx->refresh()->failure_message);
    }
}

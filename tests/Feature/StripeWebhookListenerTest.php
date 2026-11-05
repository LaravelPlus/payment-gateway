<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Tests\Feature;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use LaravelPlus\PaymentGateway\DTOs\WebhookPayload;
use LaravelPlus\PaymentGateway\Events\PaymentWebhookReceived;
use LaravelPlus\PaymentGateway\Events\RefundProcessed;
use LaravelPlus\PaymentGateway\Listeners\HandleStripeWebhook;
use LaravelPlus\PaymentGateway\Mail\DisputeAlertMail;
use LaravelPlus\PaymentGateway\Mail\InvoicePaymentFailedMail;
use LaravelPlus\PaymentGateway\Models\PaymentCustomer;
use LaravelPlus\PaymentGateway\Models\Subscription;
use LaravelPlus\PaymentGateway\Models\Transaction;
use LaravelPlus\PaymentGateway\Tests\TestCase;
use Stripe\Refund;
use Stripe\StripeClient;

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

    private function customer(): User
    {
        // The uuid column comes from the host app's migration.
        Schema::table('payment_customers', fn ($table) => $table->uuid('uuid')->nullable());

        $user = User::forceCreate(['name' => 'A', 'email' => 'a@example.com', 'password' => 'x']);
        PaymentCustomer::create(['user_id' => $user->id, 'stripe_id' => 'cus_1', 'email' => $user->email]);

        return $user;
    }

    public function test_refunds_missing_from_the_event_are_fetched_from_stripe(): void
    {
        Event::fake([RefundProcessed::class]);
        $this->app->bind(StripeClient::class, fn () => new class
        {
            public object $refunds;

            public function __construct()
            {
                $this->refunds = new class
                {
                    public function all(array $params): object
                    {
                        return (object) ['data' => [Refund::constructFrom([
                            'id' => 're_1', 'amount' => 500, 'currency' => 'eur', 'status' => 'succeeded', 'reason' => null,
                        ])]];
                    }
                };
            }
        });

        $this->dispatch('charge.refunded', ['id' => 'ch_2', 'object' => 'charge', 'amount' => 1000, 'currency' => 'eur', 'refunded' => false]);

        $this->assertDatabaseHas('payment_refunds', ['provider_id' => 're_1', 'amount' => 500]);
        Event::assertDispatched(RefundProcessed::class);
    }

    public function test_a_failed_invoice_mails_the_customer_only_once(): void
    {
        Mail::fake();
        $this->customer();

        $invoice = ['id' => 'in_1', 'object' => 'invoice', 'customer' => 'cus_1', 'amount_due' => 900, 'currency' => 'eur', 'hosted_invoice_url' => 'https://example.com/i'];
        $this->dispatch('invoice.payment_failed', $invoice);
        $this->dispatch('invoice.payment_failed', $invoice);

        Mail::assertQueued(InvoicePaymentFailedMail::class, 1);
    }

    public function test_a_subscription_created_from_a_webhook_gets_a_plan_and_keeps_it(): void
    {
        $this->customer();
        $subscription = [
            'id' => 'sub_9', 'object' => 'subscription', 'customer' => 'cus_1', 'status' => 'active', 'currency' => 'eur',
            'metadata' => ['plan' => 'pro'],
            'items' => ['data' => [['price' => ['id' => 'price_1', 'unit_amount' => 900, 'recurring' => ['interval' => 'month', 'interval_count' => 1]], 'current_period_end' => 1800000000]]],
        ];

        $this->dispatch('customer.subscription.created', $subscription);
        Subscription::where('provider_id', 'sub_9')->update(['plan_id' => 'business']);
        $this->dispatch('customer.subscription.updated', $subscription);

        $stored = Subscription::where('provider_id', 'sub_9')->firstOrFail();
        $this->assertSame('business', $stored->plan_id);
        $this->assertSame(1800000000, $stored->current_period_end->getTimestamp());
    }

    public function test_a_dispute_alerts_the_admin(): void
    {
        Mail::fake();
        config()->set('payment-gateway.notifications.admin_email', 'owner@example.com');

        $this->dispatch('charge.dispute.created', ['id' => 'dp_1', 'charge' => 'ch_1', 'amount' => 900, 'currency' => 'eur', 'reason' => 'fraudulent', 'status' => 'needs_response']);

        Mail::assertQueued(DisputeAlertMail::class, fn ($mail) => $mail->hasTo('owner@example.com'));
    }
}

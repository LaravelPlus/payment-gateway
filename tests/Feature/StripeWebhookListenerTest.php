<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Tests\Feature;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use LaravelPlus\PaymentGateway\DTOs\WebhookPayload;
use LaravelPlus\PaymentGateway\Events\PaymentWebhookReceived;
use LaravelPlus\PaymentGateway\Events\RefundProcessed;
use LaravelPlus\PaymentGateway\Listeners\HandleStripeWebhook;
use LaravelPlus\PaymentGateway\Mail\DisputeAlertMail;
use LaravelPlus\PaymentGateway\Mail\InvoicePaymentFailedMail;
use LaravelPlus\PaymentGateway\Models\Invoice;
use LaravelPlus\PaymentGateway\Models\PaymentCustomer;
use LaravelPlus\PaymentGateway\Models\Plan;
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

    public function test_a_customer_stored_on_the_user_is_found(): void
    {
        config()->set('auth.providers.users.model', User::class);
        $user = User::forceCreate(['name' => 'B', 'email' => 'b@example.com', 'password' => 'x', 'stripe_id' => 'cus_2']);

        $this->dispatch('customer.subscription.created', [
            'id' => 'sub_2', 'customer' => 'cus_2', 'status' => 'active', 'currency' => 'eur',
            'items' => ['data' => [['price' => ['id' => 'price_2', 'unit_amount' => 2900, 'recurring' => ['interval' => 'month', 'interval_count' => 1]]]]],
        ]);

        $this->assertSame($user->id, Subscription::where('provider_id', 'sub_2')->value('user_id'));
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

    /** gh-16: one Stripe subscription item, in the shape customer.subscription.* sends. */
    private static function item(string $price, int $quantity, int $amount = 900): array
    {
        return ['id' => 'si_'.$price, 'quantity' => $quantity, 'current_period_end' => 1800000000,
            'price' => ['id' => $price, 'unit_amount' => $amount, 'recurring' => ['interval' => 'month', 'interval_count' => 1]]];
    }

    private static function subscriptionObject(array $items, array $extra = []): array
    {
        return ['id' => 'sub_16', 'object' => 'subscription', 'customer' => 'cus_1', 'status' => 'active', 'currency' => 'eur',
            'metadata' => [], 'items' => ['data' => $items]] + $extra;
    }

    private function plan(string $slug, string $price): Plan
    {
        return Plan::create(['slug' => $slug, 'name' => ucfirst($slug), 'amount' => 2000, 'currency' => 'EUR', 'interval' => 'month', 'stripe_price_id' => $price]);
    }

    public function test_a_webhook_subscription_is_linked_to_the_plan_that_owns_its_price(): void
    {
        $this->customer();
        $plan = $this->plan('pro', 'price_pro');

        $this->dispatch('customer.subscription.created', self::subscriptionObject([self::item('price_pro', 1, 2000)]));

        $stored = Subscription::where('provider_id', 'sub_16')->firstOrFail();
        $this->assertSame('pro', $stored->plan_id);
        $this->assertSame($plan->id, $stored->payment_plan_id);
    }

    public function test_the_base_item_is_the_one_with_a_plan_even_when_a_seat_item_comes_first(): void
    {
        $this->customer();
        $plan = $this->plan('team', 'price_team');

        $this->dispatch('customer.subscription.created', self::subscriptionObject([
            self::item('price_seat', 4, 500),
            self::item('price_team', 1, 2000),
        ]));

        $stored = Subscription::where('provider_id', 'sub_16')->firstOrFail();
        $this->assertSame(['team', $plan->id, 'price_team', 2000, 1], [$stored->plan_id, $stored->payment_plan_id, $stored->provider_plan_id, $stored->amount, $stored->quantity]);
        $this->assertCount(2, $stored->provider_response['items']['data']);
    }

    public function test_without_a_plan_row_it_falls_back_to_the_price_and_reads_the_item_quantity(): void
    {
        $this->customer();

        // No top-level quantity: Stripe omits it once items carry their own.
        $this->dispatch('customer.subscription.created', self::subscriptionObject([self::item('price_solo', 3)]));

        $stored = Subscription::where('provider_id', 'sub_16')->firstOrFail();
        $this->assertSame(['price_solo', null, 3], [$stored->plan_id, $stored->payment_plan_id, $stored->quantity]);
    }

    public function test_a_price_change_relinks_the_plan_but_keeps_the_app_owned_plan_id(): void
    {
        $this->customer();
        $this->plan('pro', 'price_pro');
        $annual = $this->plan('pro-annual', 'price_pro_annual');

        $this->dispatch('customer.subscription.created', self::subscriptionObject([self::item('price_pro', 1)]));
        $this->dispatch('customer.subscription.updated', self::subscriptionObject([self::item('price_pro_annual', 1)]));

        $stored = Subscription::where('provider_id', 'sub_16')->firstOrFail();
        $this->assertSame(['pro', $annual->id, 'price_pro_annual'], [$stored->plan_id, $stored->payment_plan_id, $stored->provider_plan_id]);
    }

    public function test_a_subscription_without_items_fails_loudly_instead_of_storing_unknown(): void
    {
        $this->customer();

        try {
            $this->dispatch('customer.subscription.created', self::subscriptionObject([]));
            $this->fail('stored a subscription with no items');
        } catch (\UnexpectedValueException $e) {
            $this->assertStringContainsString('sub_16', $e->getMessage());
        }

        $this->assertSame(0, Subscription::where('provider_id', 'sub_16')->count());
    }

    public function test_a_dispute_alerts_the_admin(): void
    {
        Mail::fake();
        config()->set('payment-gateway.notifications.admin_email', 'owner@example.com');

        $this->dispatch('charge.dispute.created', ['id' => 'dp_1', 'charge' => 'ch_1', 'amount' => 900, 'currency' => 'eur', 'reason' => 'fraudulent', 'status' => 'needs_response']);

        Mail::assertQueued(DisputeAlertMail::class, fn ($mail) => $mail->hasTo('owner@example.com'));
    }

    public function test_an_early_fraud_warning_alerts_the_admin(): void
    {
        Mail::fake();
        config()->set('payment-gateway.notifications.admin_email', 'owner@example.com');

        $this->dispatch('radar.early_fraud_warning.created', ['id' => 'issfr_1', 'charge' => 'ch_1', 'fraud_type' => 'made_with_stolen_card', 'actionable' => true]);

        Mail::assertQueued(DisputeAlertMail::class, function ($mail) {
            $html = $mail->render();

            return $mail->hasTo('owner@example.com')
                && str_contains($html, 'Early Fraud Warning')
                && str_contains($html, 'made_with_stolen_card');
        });
    }

    private function stripeInvoice(string $status, array $extra = []): array
    {
        return array_merge([
            'id' => 'in_9', 'object' => 'invoice', 'customer' => 'cus_1', 'status' => $status,
            'subtotal' => 900, 'total' => 900, 'amount_paid' => $status === 'paid' ? 900 : 0, 'amount_due' => 900,
            'currency' => 'eur', 'subscription' => 'sub_9',
            'lines' => ['data' => [['description' => 'Pro', 'quantity' => 1, 'amount' => 900, 'price' => ['unit_amount' => 900]]]],
        ], $extra);
    }

    private function stripeSubscriptionRow(User $user): Subscription
    {
        return Subscription::forceCreate([
            'user_id' => $user->id, 'driver' => 'stripe', 'provider_id' => 'sub_9', 'provider_plan_id' => 'price_1',
            'plan_id' => 'pro', 'status' => 'active', 'amount' => 900, 'currency' => 'EUR', 'interval' => 'month',
            'interval_count' => 1, 'quantity' => 1,
        ]);
    }

    public function test_finalized_then_failed_then_paid_keeps_one_invoice_linked_to_its_subscription(): void
    {
        Mail::fake();
        Storage::fake();
        $subscription = $this->stripeSubscriptionRow($this->customer());

        $this->dispatch('invoice.finalized', $this->stripeInvoice('open'));
        $this->assertSame('open', Invoice::where('provider_id', 'in_9')->value('status'));
        $this->assertNull(Invoice::where('provider_id', 'in_9')->value('pdf_path'), 'an open invoice gets no PDF');

        $this->dispatch('invoice.payment_failed', $this->stripeInvoice('open'));
        $this->dispatch('invoice.paid', $this->stripeInvoice('paid'));

        $invoice = Invoice::where('provider_id', 'in_9')->sole();
        $this->assertSame('paid', $invoice->status->value ?? $invoice->status);
        $this->assertSame($subscription->id, $invoice->subscription_id);
        $this->assertNotNull($invoice->paid_at);
        $this->assertNotNull($invoice->pdf_path);
    }

    public function test_a_late_finalized_event_does_not_reopen_a_paid_invoice(): void
    {
        Storage::fake();
        $this->customer();

        $this->dispatch('invoice.paid', $this->stripeInvoice('paid'));
        $this->dispatch('invoice.finalized', $this->stripeInvoice('open'));
        $this->dispatch('invoice.payment_failed', $this->stripeInvoice('open'));

        $invoice = Invoice::where('provider_id', 'in_9')->sole();
        $this->assertSame('paid', $invoice->getStatusEnum()->value);
        // The stale open snapshots must not rewrite what was paid.
        $this->assertSame([900, 'paid'], [(int) $invoice->amount_paid, $invoice->provider_response['status']]);
    }

    public function test_a_late_event_still_links_the_subscription_of_a_paid_invoice(): void
    {
        Storage::fake();
        $user = $this->customer();

        $this->dispatch('invoice.paid', $this->stripeInvoice('paid'));
        $subscription = $this->stripeSubscriptionRow($user);
        $this->dispatch('invoice.finalized', $this->stripeInvoice('open'));

        $this->assertSame($subscription->id, Invoice::where('provider_id', 'in_9')->value('subscription_id'));
    }

    public function test_invoice_sync_waits_for_the_per_invoice_lock_and_writes_nothing_without_it(): void
    {
        config(['payment-gateway.webhooks.lock_wait' => 0]);
        Storage::fake();
        $this->customer();

        // Another worker is mid-sync on this invoice.
        $held = Cache::lock('payment-gateway:invoice-sync:in_9', 30);
        $this->assertTrue($held->get());

        try {
            $this->dispatch('invoice.paid', $this->stripeInvoice('paid'));
            $this->fail('synced without the lock');
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            // A 5xx: Stripe retries once the other worker is done.
            $this->assertSame(0, Invoice::where('provider_id', 'in_9')->count());
        }

        $held->release();
        $this->dispatch('invoice.paid', $this->stripeInvoice('paid'));
        $this->assertSame(1, Invoice::where('provider_id', 'in_9')->count());
    }

    public function test_a_duplicate_paid_event_creates_no_second_invoice_or_pdf(): void
    {
        Storage::fake();
        $this->customer();

        $this->dispatch('invoice.paid', $this->stripeInvoice('paid'));
        $this->dispatch('invoice.paid', $this->stripeInvoice('paid'));

        $this->assertSame(1, Invoice::where('provider_id', 'in_9')->count());
        $this->assertCount(1, Storage::allFiles());
    }

    public function test_void_and_uncollectible_are_kept_apart_and_uncollectible_can_still_be_paid(): void
    {
        Storage::fake();
        $this->customer();

        $this->dispatch('invoice.marked_uncollectible', $this->stripeInvoice('uncollectible'));
        $this->assertSame('uncollectible', Invoice::where('provider_id', 'in_9')->value('status'));

        $this->dispatch('invoice.paid', $this->stripeInvoice('paid'));
        $this->assertSame('paid', Invoice::where('provider_id', 'in_9')->value('status'));

        $this->dispatch('invoice.voided', $this->stripeInvoice('void', ['id' => 'in_10']));
        $this->dispatch('invoice.finalized', $this->stripeInvoice('open', ['id' => 'in_10']));
        $this->assertSame('void', Invoice::where('provider_id', 'in_10')->value('status'));
    }

    public function test_the_subscription_is_found_on_newer_api_invoices(): void
    {
        Storage::fake();
        $subscription = $this->stripeSubscriptionRow($this->customer());

        $this->dispatch('invoice.finalized', $this->stripeInvoice('open', [
            'subscription' => null,
            'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_9']],
        ]));

        $this->assertSame($subscription->id, Invoice::where('provider_id', 'in_9')->value('subscription_id'));
    }

    public function test_an_invoice_for_an_unknown_customer_is_logged_not_stored(): void
    {
        Log::spy();

        $this->dispatch('invoice.paid', $this->stripeInvoice('paid', ['customer' => 'cus_unknown']));

        $this->assertSame(0, Invoice::count());
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => ($context['customer'] ?? null) === 'cus_unknown')->once();
    }

    public function test_a_repeated_payment_intent_creates_one_invoice(): void
    {
        Storage::fake();
        $user = $this->customer();
        Transaction::create(['user_id' => $user->id, 'driver' => 'stripe', 'provider_id' => 'pi_7', 'amount' => 1000, 'currency' => 'EUR', 'status' => 'pending']);

        $this->dispatch('payment_intent.succeeded', ['id' => 'pi_7', 'object' => 'payment_intent']);
        $this->dispatch('payment_intent.succeeded', ['id' => 'pi_7', 'object' => 'payment_intent']);

        $this->assertSame(1, Invoice::count());
    }

    public function test_a_payment_intent_of_a_stripe_invoice_leaves_the_invoice_to_the_invoice_events(): void
    {
        Storage::fake();
        $user = $this->customer();
        Transaction::create(['user_id' => $user->id, 'driver' => 'stripe', 'provider_id' => 'pi_8', 'amount' => 900, 'currency' => 'EUR', 'status' => 'pending']);

        $this->dispatch('payment_intent.succeeded', ['id' => 'pi_8', 'object' => 'payment_intent', 'invoice' => 'in_9']);
        $this->dispatch('invoice.paid', $this->stripeInvoice('paid'));

        $this->assertSame(1, Invoice::count());
    }

    public function test_an_invoice_that_beats_its_subscription_is_linked_when_the_subscription_arrives(): void
    {
        Storage::fake();
        $this->customer();

        $this->dispatch('invoice.paid', $this->stripeInvoice('paid'));
        $this->dispatch('customer.subscription.created', [
            'id' => 'sub_9', 'object' => 'subscription', 'customer' => 'cus_1', 'status' => 'active', 'currency' => 'eur',
            'items' => ['data' => [['price' => ['id' => 'price_1', 'unit_amount' => 900, 'recurring' => ['interval' => 'month', 'interval_count' => 1]]]]],
        ]);

        $this->assertSame(
            Subscription::where('provider_id', 'sub_9')->value('id'),
            Invoice::where('provider_id', 'in_9')->value('subscription_id'),
        );
    }

    public function test_a_renewal_gets_its_own_invoice_on_the_same_subscription(): void
    {
        Storage::fake();
        $subscription = $this->stripeSubscriptionRow($this->customer());

        $this->dispatch('invoice.paid', $this->stripeInvoice('paid', ['billing_reason' => 'subscription_create']));
        $this->dispatch('invoice.paid', $this->stripeInvoice('paid', ['id' => 'in_11', 'billing_reason' => 'subscription_cycle']));

        $this->assertSame([$subscription->id, $subscription->id], Invoice::orderBy('id')->pluck('subscription_id')->all());
    }

    public function test_zero_value_and_partly_paid_invoices_keep_stripe_amounts(): void
    {
        Storage::fake();
        $this->customer();

        $this->dispatch('invoice.paid', $this->stripeInvoice('paid', ['subtotal' => 0, 'total' => 0, 'amount_paid' => 0, 'amount_due' => 0]));
        $this->dispatch('invoice.payment_failed', $this->stripeInvoice('open', ['id' => 'in_12', 'amount_paid' => 400, 'amount_due' => 900]));

        $free = Invoice::where('provider_id', 'in_9')->sole();
        $this->assertSame([0, 'paid'], [$free->total, $free->getStatusEnum()->value]);
        $partial = Invoice::where('provider_id', 'in_12')->sole();
        $this->assertSame([400, 900, 'open'], [$partial->amount_paid, $partial->amount_due, $partial->getStatusEnum()->value]);
    }
}

<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Tests\Feature;

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use LaravelPlus\PaymentGateway\DTOs\Customer;
use LaravelPlus\PaymentGateway\DTOs\WebhookPayload;
use LaravelPlus\PaymentGateway\Drivers\PayPalGateway;
use LaravelPlus\PaymentGateway\Events\PaymentWebhookReceived;
use LaravelPlus\PaymentGateway\Events\SubscriptionCanceled;
use LaravelPlus\PaymentGateway\Events\SubscriptionCreated;
use LaravelPlus\PaymentGateway\Listeners\HandlePayPalWebhook;
use LaravelPlus\PaymentGateway\Models\Invoice;
use LaravelPlus\PaymentGateway\Models\PaymentCustomer;
use LaravelPlus\PaymentGateway\Models\Plan;
use LaravelPlus\PaymentGateway\Models\Refund;
use LaravelPlus\PaymentGateway\Models\Subscription;
use LaravelPlus\PaymentGateway\Models\Transaction;
use LaravelPlus\PaymentGateway\Tests\TestCase;
use UnexpectedValueException;

/**
 * gh-26: PayPal webhooks match rows by (driver, provider_id), persist every
 * required subscription column, and tolerate out-of-order delivery. All IDs are synthetic.
 */
class PayPalWebhookListenerTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('payment-gateway.billable_model', User::class);
    }

    private function dispatch(string $type, array $resource): void
    {
        app(HandlePayPalWebhook::class)->handle(new PaymentWebhookReceived(
            new WebhookPayload('WH-'.uniqid(), $type, 'paypal', $resource)
        ));
    }

    private function customer(string $payerId = 'PAYER1'): User
    {
        Schema::hasColumn('payment_customers', 'uuid') || Schema::table('payment_customers', fn ($table) => $table->uuid('uuid')->nullable());

        $user = User::forceCreate(['name' => 'A', 'email' => 'a@example.com', 'password' => 'x']);
        PaymentCustomer::create(['user_id' => $user->id, 'paypal_id' => $payerId, 'email' => $user->email]);

        return $user;
    }

    private function plan(): Plan
    {
        return Plan::create(['slug' => 'pro', 'name' => 'Pro', 'amount' => 1500, 'currency' => 'EUR', 'interval' => 'year', 'interval_count' => 1, 'paypal_plan_id' => 'P-PRO']);
    }

    private static function subscription(string $status, string $updatedAt, array $extra = []): array
    {
        return array_replace_recursive([
            'id' => 'I-SUB1', 'plan_id' => 'P-PRO', 'status' => $status, 'status_update_time' => $updatedAt,
            'start_time' => '2026-01-01T00:00:00Z', 'subscriber' => ['payer_id' => 'PAYER1'],
            'billing_info' => ['next_billing_time' => '2027-01-01T00:00:00Z'],
        ], $extra);
    }

    private function row(): Subscription
    {
        return Subscription::where('driver', 'paypal')->where('provider_id', 'I-SUB1')->sole();
    }

    public function test_subscription_lifecycle_keeps_one_row_with_every_required_field(): void
    {
        Event::fake([SubscriptionCreated::class, SubscriptionCanceled::class]);
        $user = $this->customer();
        $plan = $this->plan();

        $this->dispatch('BILLING.SUBSCRIPTION.CREATED', self::subscription('APPROVAL_PENDING', '2026-01-01T00:00:00Z'));
        $this->assertSame(
            [$user->id, 'pro', $plan->id, 'P-PRO', 'year', 1, 1500, 'EUR', 'incomplete'],
            [$this->row()->user_id, $this->row()->plan_id, $this->row()->payment_plan_id, $this->row()->provider_plan_id, $this->row()->interval, $this->row()->interval_count, (int) $this->row()->amount, $this->row()->currency, $this->row()->status],
        );

        $this->dispatch('BILLING.SUBSCRIPTION.ACTIVATED', self::subscription('ACTIVE', '2026-01-01T00:01:00Z'));
        $this->assertSame('active', $this->row()->status);

        $this->dispatch('BILLING.SUBSCRIPTION.UPDATED', self::subscription('ACTIVE', '2026-02-01T00:00:00Z', ['quantity' => '3']));
        $this->assertSame(3, $this->row()->quantity);

        $this->dispatch('BILLING.SUBSCRIPTION.SUSPENDED', self::subscription('SUSPENDED', '2026-03-01T00:00:00Z'));
        $this->assertSame('paused', $this->row()->status);

        $this->dispatch('BILLING.SUBSCRIPTION.CANCELLED', self::subscription('CANCELLED', '2026-04-01T00:00:00Z'));
        $this->assertSame('canceled', $this->row()->status);
        $this->assertNotNull($this->row()->canceled_at);

        $this->assertSame(1, Subscription::count());
        Event::assertDispatchedTimes(SubscriptionCreated::class, 1);
        Event::assertDispatchedTimes(SubscriptionCanceled::class, 1);
    }

    public function test_events_out_of_order_create_the_row_once_and_never_roll_status_back(): void
    {
        Event::fake([SubscriptionCreated::class]);
        $this->customer();
        $this->plan();

        // ACTIVATED beats CREATED: the row is created from it, and the older CREATED snapshot is ignored.
        $this->dispatch('BILLING.SUBSCRIPTION.ACTIVATED', self::subscription('ACTIVE', '2026-01-01T00:01:00Z'));
        $this->dispatch('BILLING.SUBSCRIPTION.CREATED', self::subscription('APPROVAL_PENDING', '2026-01-01T00:00:00Z'));
        $this->assertSame('active', $this->row()->status);
        Event::assertDispatchedTimes(SubscriptionCreated::class, 1);

        // A cancellation is final, even against a snapshot without a timestamp.
        $this->dispatch('BILLING.SUBSCRIPTION.CANCELLED', self::subscription('CANCELLED', '2026-05-01T00:00:00Z'));
        $this->dispatch('BILLING.SUBSCRIPTION.ACTIVATED', self::subscription('ACTIVE', '2026-01-01T00:01:00Z', ['status_update_time' => null]));
        $this->assertSame('canceled', $this->row()->status);
    }

    public function test_expiry_ends_the_subscription(): void
    {
        $this->customer();
        $this->plan();

        $this->dispatch('BILLING.SUBSCRIPTION.EXPIRED', self::subscription('EXPIRED', '2027-01-01T00:00:00Z'));

        $this->assertSame('expired', $this->row()->status);
        $this->assertNotNull($this->row()->ended_at);
    }

    public function test_without_a_local_plan_the_paypal_plan_id_is_kept_and_nothing_is_invented_beyond_defaults(): void
    {
        $this->customer();

        $this->dispatch('BILLING.SUBSCRIPTION.ACTIVATED', self::subscription('ACTIVE', '2026-01-01T00:00:00Z', [
            'billing_info' => ['last_payment' => ['amount' => ['currency_code' => 'USD', 'value' => '9.99']]],
        ]));

        $this->assertSame(['P-PRO', null, 999, 'USD'], [$this->row()->plan_id, $this->row()->payment_plan_id, (int) $this->row()->amount, $this->row()->currency]);
    }

    public function test_an_unknown_payer_fails_loudly_and_grants_nothing(): void
    {
        $this->plan();

        try {
            $this->dispatch('BILLING.SUBSCRIPTION.ACTIVATED', self::subscription('ACTIVE', '2026-01-01T00:00:00Z'));
            $this->fail('created a subscription for nobody');
        } catch (UnexpectedValueException $e) {
            $this->assertStringContainsString('I-SUB1', $e->getMessage());
        }

        $this->assertSame(0, Subscription::count());
    }

    public function test_a_subscriber_is_found_by_custom_id_when_the_payer_is_new(): void
    {
        $user = $this->customer('PP-CUST-7');
        $this->plan();

        $this->dispatch('BILLING.SUBSCRIPTION.ACTIVATED', self::subscription('ACTIVE', '2026-01-01T00:00:00Z', [
            'custom_id' => 'PP-CUST-7', 'subscriber' => ['payer_id' => 'NEWPAYER'],
        ]));

        $this->assertSame($user->id, $this->row()->user_id);
    }

    public function test_create_subscription_sends_the_customer_as_custom_id(): void
    {
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            '*/v1/billing/subscriptions' => Http::response(['id' => 'I-SUB1', 'status' => 'APPROVAL_PENDING']),
        ]);

        (new PayPalGateway(['client_id' => 'x', 'client_secret' => 'y', 'mode' => 'sandbox']))
            ->createSubscription(new Customer(id: 'PP-CUST-7', email: 'a@example.com'), 'P-PRO', '');

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/v1/billing/subscriptions') && $r['custom_id'] === 'PP-CUST-7');
    }

    private function orderTransaction(User $user): Transaction
    {
        // charge() stores the order id: PayPal's capture response is the order.
        return Transaction::create(['user_id' => $user->id, 'driver' => 'paypal', 'provider_id' => 'ORDER-1', 'amount' => 2500, 'currency' => 'EUR', 'status' => 'pending']);
    }

    private static function capture(array $extra = []): array
    {
        return array_replace_recursive([
            'id' => 'CAPTURE-1', 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'EUR', 'value' => '25.00'],
            'supplementary_data' => ['related_ids' => ['order_id' => 'ORDER-1']],
        ], $extra);
    }

    public function test_capture_completed_finds_the_order_transaction_and_invoices_once(): void
    {
        Storage::fake();
        $tx = $this->orderTransaction($this->customer());

        $this->dispatch('PAYMENT.CAPTURE.COMPLETED', self::capture());
        $this->dispatch('PAYMENT.CAPTURE.COMPLETED', self::capture());
        $this->dispatch('CHECKOUT.ORDER.COMPLETED', ['id' => 'ORDER-1', 'status' => 'COMPLETED',
            'purchase_units' => [['payments' => ['captures' => [['id' => 'CAPTURE-1']]]]]]);

        $this->assertSame('succeeded', $tx->refresh()->status);
        $this->assertSame(1, Invoice::count());
    }

    public function test_order_completed_alone_settles_the_transaction(): void
    {
        Storage::fake();
        $tx = $this->orderTransaction($this->customer());

        $this->dispatch('CHECKOUT.ORDER.COMPLETED', ['id' => 'ORDER-1', 'status' => 'COMPLETED']);

        $this->assertSame('succeeded', $tx->refresh()->status);
        $this->assertSame(1, Invoice::count());
    }

    public function test_capture_denied_fails_the_transaction(): void
    {
        $tx = $this->orderTransaction($this->customer());

        $this->dispatch('PAYMENT.CAPTURE.DENIED', self::capture(['status' => 'DECLINED', 'status_details' => ['reason' => 'RISK']]));

        $this->assertSame(['failed', 'RISK'], [$tx->refresh()->status, $tx->failure_message]);
    }

    public function test_capture_refunded_reads_the_capture_from_the_refund_resource(): void
    {
        $tx = $this->orderTransaction($this->customer());

        // The resource of PAYMENT.CAPTURE.REFUNDED is the refund; its "up" link is the capture.
        $this->dispatch('PAYMENT.CAPTURE.REFUNDED', [
            'id' => 'REFUND-1', 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'EUR', 'value' => '25.00'],
            'links' => [['rel' => 'up', 'href' => 'https://api-m.sandbox.paypal.com/v2/payments/captures/CAPTURE-1']],
            'supplementary_data' => ['related_ids' => ['order_id' => 'ORDER-1', 'capture_id' => 'CAPTURE-1']],
        ]);

        $refund = Refund::where('provider_id', 'REFUND-1')->sole();
        $this->assertSame([$tx->id, 2500], [$refund->transaction_id, (int) $refund->amount]);
        $this->assertSame('refunded', $tx->refresh()->status);
    }

    public function test_an_unknown_capture_is_logged_not_failed(): void
    {
        Log::spy();

        $this->dispatch('PAYMENT.CAPTURE.COMPLETED', self::capture(['supplementary_data' => ['related_ids' => ['order_id' => 'ORDER-X']]]));

        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => str_contains($message, 'PayPal') && $context['ids'] === ['CAPTURE-1', 'ORDER-X']);
        $this->assertSame(0, Transaction::count());
    }

    public function test_a_subscription_sale_is_recorded_once_against_its_subscription(): void
    {
        Storage::fake();
        $user = $this->customer();
        $this->plan();
        $this->dispatch('BILLING.SUBSCRIPTION.ACTIVATED', self::subscription('ACTIVE', '2026-01-01T00:00:00Z'));

        $sale = ['id' => 'SALE-1', 'billing_agreement_id' => 'I-SUB1', 'amount' => ['total' => '15.00', 'currency' => 'EUR']];
        $this->dispatch('PAYMENT.SALE.COMPLETED', $sale);
        $this->dispatch('PAYMENT.SALE.COMPLETED', $sale);

        $tx = Transaction::where('driver', 'paypal')->where('provider_id', 'SALE-1')->sole();
        $this->assertSame([$user->id, Subscription::class, $this->row()->id, 1500], [$tx->user_id, $tx->payable_type, $tx->payable_id, (int) $tx->amount]);
        $this->assertSame(1, Invoice::count());

        $this->dispatch('PAYMENT.SALE.REFUNDED', ['id' => 'SALE-REFUND-1', 'sale_id' => 'SALE-1', 'amount' => ['total' => '15.00', 'currency' => 'EUR']]);
        $this->assertSame($tx->id, Refund::where('provider_id', 'SALE-REFUND-1')->value('transaction_id'));
    }

    public function test_a_sale_before_its_subscription_is_retried_not_orphaned(): void
    {
        $this->expectException(UnexpectedValueException::class);

        try {
            $this->dispatch('PAYMENT.SALE.COMPLETED', ['id' => 'SALE-1', 'billing_agreement_id' => 'I-UNKNOWN', 'amount' => ['total' => '15.00', 'currency' => 'EUR']]);
        } finally {
            $this->assertSame(0, Transaction::count());
        }
    }

    public function test_the_cleanup_command_can_mark_a_subscription_expired_now_the_status_exists(): void
    {
        $user = $this->customer();
        $sub = Subscription::forceCreate([
            'user_id' => $user->id, 'driver' => 'paypal', 'provider_id' => 'I-OLD', 'provider_plan_id' => 'P-PRO', 'plan_id' => 'pro',
            'status' => 'active', 'amount' => 1500, 'currency' => 'EUR', 'interval' => 'month', 'interval_count' => 1, 'quantity' => 1,
            'current_period_end' => now()->subDays(2),
        ]);

        $this->artisan('payment-gateway:cleanup-subscriptions')->assertSuccessful();

        $this->assertSame('expired', $sub->refresh()->status);
    }
}

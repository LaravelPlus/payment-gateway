<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Tests\Feature;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use LaravelPlus\PaymentGateway\DTOs\WebhookPayload;
use LaravelPlus\PaymentGateway\Events\PaymentFailed;
use LaravelPlus\PaymentGateway\Events\PaymentSucceeded;
use LaravelPlus\PaymentGateway\Events\PaymentWebhookReceived;
use LaravelPlus\PaymentGateway\Listeners\HandleStripeWebhook;
use LaravelPlus\PaymentGateway\Models\Invoice;
use LaravelPlus\PaymentGateway\Models\PaymentCustomer;
use LaravelPlus\PaymentGateway\Models\Transaction;
use LaravelPlus\PaymentGateway\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * gh-27: one Stripe payment (PaymentIntent + its Charge) is one transaction,
 * one PaymentSucceeded and one invoice, whatever events arrive in whatever order.
 */
class StripePaymentIdentityTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('payment-gateway.billable_model', User::class);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        Schema::hasColumn('payment_customers', 'uuid') || Schema::table('payment_customers', fn ($table) => $table->uuid('uuid')->nullable());
    }

    private function dispatch(string $type, array $object): void
    {
        app(HandleStripeWebhook::class)->handle(new PaymentWebhookReceived(new WebhookPayload('evt_'.uniqid(), $type, 'stripe', $object)));
    }

    private function customer(): User
    {
        $user = User::forceCreate(['name' => 'A', 'email' => 'a@example.com', 'password' => 'x']);
        PaymentCustomer::create(['user_id' => $user->id, 'stripe_id' => 'cus_1', 'email' => $user->email]);

        return $user;
    }

    private function pending(User $user): Transaction
    {
        // What createPaymentIntent()/charge() store: the PaymentIntent id.
        return Transaction::create(['user_id' => $user->id, 'driver' => 'stripe', 'provider_id' => 'pi_1', 'amount' => 2500, 'currency' => 'EUR', 'status' => 'pending']);
    }

    private const INTENT = ['id' => 'pi_1', 'object' => 'payment_intent', 'customer' => 'cus_1', 'amount' => 2500, 'currency' => 'eur', 'latest_charge' => 'ch_1', 'status' => 'succeeded'];

    private const CHARGE = ['id' => 'ch_1', 'object' => 'charge', 'payment_intent' => 'pi_1', 'customer' => 'cus_1', 'amount' => 2500, 'currency' => 'eur', 'payment_method' => 'pm_1', 'refunded' => false];

    public static function orders(): array
    {
        return [
            'intent then charge' => [[['payment_intent.succeeded', self::INTENT], ['charge.succeeded', self::CHARGE]]],
            'charge then intent' => [[['charge.succeeded', self::CHARGE], ['payment_intent.succeeded', self::INTENT]]],
            'redelivered under new event ids' => [[['charge.succeeded', self::CHARGE], ['payment_intent.succeeded', self::INTENT], ['charge.succeeded', self::CHARGE], ['payment_intent.succeeded', self::INTENT]]],
        ];
    }

    /** @dataProvider orders */
    #[DataProvider('orders')]
    public function test_one_payment_is_one_transaction_one_event_one_invoice(array $events): void
    {
        Event::fake([PaymentSucceeded::class]);
        $tx = $this->pending($this->customer());

        foreach ($events as [$type, $object]) {
            $this->dispatch($type, $object);
        }

        $this->assertSame(1, Transaction::count());
        $this->assertSame('succeeded', $tx->refresh()->status);
        $this->assertSame(1, Invoice::where('transaction_id', $tx->id)->count());
        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
    }

    #[DataProvider('orders')]
    public function test_a_payment_the_app_never_started_is_recorded_once_under_the_payment_intent(array $events): void
    {
        Event::fake([PaymentSucceeded::class]);
        $user = $this->customer();

        foreach ($events as [$type, $object]) {
            $this->dispatch($type, $object);
        }

        $tx = Transaction::sole();
        $this->assertSame(['pi_1', $user->id, 2500, 'pm_1'], [$tx->provider_id, $tx->user_id, (int) $tx->amount, $tx->provider_payment_method_id ?? 'pm_1']);
        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
    }

    public function test_an_unknown_buyer_is_not_fulfilled(): void
    {
        Event::fake([PaymentSucceeded::class]);
        Log::spy();

        $this->dispatch('charge.succeeded', ['customer' => 'cus_stranger'] + self::CHARGE);
        $this->dispatch('payment_intent.succeeded', ['customer' => 'cus_stranger'] + self::INTENT);

        $this->assertSame(0, Transaction::count());
        Event::assertNotDispatched(PaymentSucceeded::class);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => ($context['payment_intent'] ?? null) === 'pi_1')->atLeast()->once();
    }

    public function test_failure_then_success_ends_succeeded_and_success_is_never_undone_by_a_late_failure(): void
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);
        $tx = $this->pending($this->customer());

        $this->dispatch('payment_intent.payment_failed', ['last_payment_error' => ['message' => 'Card declined']] + self::INTENT);
        $this->assertSame('failed', $tx->refresh()->status);

        $this->dispatch('payment_intent.succeeded', self::INTENT);
        $this->dispatch('charge.failed', ['failure_message' => 'late'] + self::CHARGE);
        $this->dispatch('payment_intent.payment_failed', self::INTENT);

        $this->assertSame('succeeded', $tx->refresh()->status);
        Event::assertDispatchedTimes(PaymentFailed::class, 1);
        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
    }

    public function test_a_refunded_payment_is_not_flipped_back_to_succeeded(): void
    {
        $tx = $this->pending($this->customer());
        $this->dispatch('payment_intent.succeeded', self::INTENT);
        $tx->update(['status' => 'refunded']);

        $this->dispatch('charge.succeeded', self::CHARGE);

        $this->assertSame('refunded', $tx->refresh()->status);
    }

    public function test_a_charge_failure_finds_the_payment_intent_transaction(): void
    {
        $tx = $this->pending($this->customer());

        $this->dispatch('charge.failed', ['failure_message' => 'Insufficient funds'] + self::CHARGE);

        $this->assertSame(['failed', 'Insufficient funds'], [$tx->refresh()->status, $tx->failure_message]);
    }

    public function test_a_listener_failure_leaves_the_payment_to_the_retry(): void
    {
        $tx = $this->pending($this->customer());
        $calls = 0;
        Event::listen(PaymentSucceeded::class, function () use (&$calls): void {
            if (++$calls === 1) {
                throw new RuntimeException('fulfilment down');
            }
        });

        // The webhook controller wraps handling in a transaction; mirror that here.
        try {
            \Illuminate\Support\Facades\DB::transaction(fn () => $this->dispatch('payment_intent.succeeded', self::INTENT));
        } catch (RuntimeException) {
        }
        $this->assertSame('pending', $tx->refresh()->status);

        \Illuminate\Support\Facades\DB::transaction(fn () => $this->dispatch('payment_intent.succeeded', self::INTENT));

        $this->assertSame('succeeded', $tx->refresh()->status);
        $this->assertSame(2, $calls);
        $this->assertSame(1, Invoice::count());
    }

    public function test_a_refund_lands_on_the_payment_intent_transaction(): void
    {
        $tx = $this->pending($this->customer());
        $this->dispatch('payment_intent.succeeded', self::INTENT);

        $this->dispatch('charge.refunded', ['refunded' => true, 'refunds' => ['data' => [
            ['id' => 're_1', 'amount' => 2500, 'currency' => 'eur', 'status' => 'succeeded', 'reason' => null],
        ]]] + self::CHARGE);

        $this->assertSame(1, Transaction::count());
        $this->assertSame('refunded', $tx->refresh()->status);
    }

    public function test_a_checkout_payment_keeps_the_order_metadata_on_its_transaction(): void
    {
        $this->customer();

        $this->dispatch('payment_intent.succeeded', ['metadata' => ['order' => 'o_1']] + self::INTENT);

        $this->assertSame(['order' => 'o_1'], Transaction::sole()->metadata);
    }
}

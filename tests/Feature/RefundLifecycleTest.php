<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Tests\Feature;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use LaravelPlus\PaymentGateway\Contracts\SupportsRefunds;
use LaravelPlus\PaymentGateway\DTOs\WebhookPayload;
use LaravelPlus\PaymentGateway\Events\PaymentWebhookReceived;
use LaravelPlus\PaymentGateway\Events\RefundProcessed;
use LaravelPlus\PaymentGateway\Facades\Payment;
use LaravelPlus\PaymentGateway\Http\Controllers\Admin\TransactionController;
use LaravelPlus\PaymentGateway\Http\Requests\Admin\RefundTransactionRequest;
use LaravelPlus\PaymentGateway\Listeners\HandleStripeWebhook;
use LaravelPlus\PaymentGateway\Models\PaymentCustomer;
use LaravelPlus\PaymentGateway\Models\Refund;
use LaravelPlus\PaymentGateway\Models\Transaction;
use LaravelPlus\PaymentGateway\Tests\TestCase;
use Mockery;

/**
 * gh-25: only settled refunds count towards amount_refunded and the payment's
 * refunded / partially_refunded status, and RefundProcessed fires once per settled refund.
 */
class RefundLifecycleTest extends TestCase
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

    private function paid(int $amount = 1000): Transaction
    {
        $user = User::forceCreate(['name' => 'A', 'email' => uniqid().'@example.com', 'password' => 'x']);
        PaymentCustomer::create(['user_id' => $user->id, 'stripe_id' => 'cus_1', 'email' => $user->email]);

        return Transaction::create(['user_id' => $user->id, 'driver' => 'stripe', 'provider_id' => 'pi_1', 'amount' => $amount, 'currency' => 'EUR', 'status' => 'succeeded']);
    }

    private function refundEvent(string $id, int $amount, string $status, string $type = 'charge.refund.updated'): void
    {
        app(HandleStripeWebhook::class)->handle(new PaymentWebhookReceived(new WebhookPayload('evt_'.uniqid(), $type, 'stripe', [
            'id' => $id, 'object' => 'refund', 'amount' => $amount, 'currency' => 'eur', 'status' => $status,
            'charge' => 'ch_1', 'payment_intent' => 'pi_1', 'reason' => null,
        ])));
    }

    private function state(Transaction $tx): array
    {
        $tx->refresh();

        return [(int) $tx->amount_refunded, $tx->status];
    }

    public function test_pending_then_succeeded_counts_only_once_settled(): void
    {
        Event::fake([RefundProcessed::class]);
        $tx = $this->paid();

        $this->refundEvent('re_1', 1000, 'pending', 'refund.created');
        $this->assertSame([0, 'succeeded'], $this->state($tx));
        Event::assertNotDispatched(RefundProcessed::class);

        $this->refundEvent('re_1', 1000, 'succeeded');
        $this->assertSame([1000, 'refunded'], $this->state($tx));
        Event::assertDispatchedTimes(RefundProcessed::class, 1);
    }

    public function test_pending_then_failed_never_counts(): void
    {
        Event::fake([RefundProcessed::class]);
        $tx = $this->paid();

        $this->refundEvent('re_1', 1000, 'pending', 'refund.created');
        $this->refundEvent('re_1', 1000, 'failed', 'refund.failed');

        $this->assertSame([0, 'succeeded'], $this->state($tx));
        $this->assertSame('failed', Refund::where('provider_id', 're_1')->value('status'));
        Event::assertNotDispatched(RefundProcessed::class);
    }

    public function test_a_repeated_succeeded_event_counts_once(): void
    {
        Event::fake([RefundProcessed::class]);
        $tx = $this->paid();

        $this->refundEvent('re_1', 400, 'succeeded');
        $this->refundEvent('re_1', 400, 'succeeded');
        $this->refundEvent('re_1', 400, 'succeeded', 'refund.updated');

        $this->assertSame([400, 'partially_refunded'], $this->state($tx));
        Event::assertDispatchedTimes(RefundProcessed::class, 1);
    }

    public function test_two_partial_refunds_reach_the_total(): void
    {
        $tx = $this->paid();

        $this->refundEvent('re_1', 400, 'succeeded');
        $this->assertSame([400, 'partially_refunded'], $this->state($tx));

        $this->refundEvent('re_2', 600, 'succeeded');
        $this->assertSame([1000, 'refunded'], $this->state($tx));
    }

    public function test_totals_never_exceed_the_payment_or_go_negative(): void
    {
        $tx = $this->paid();

        $this->refundEvent('re_1', 800, 'succeeded');
        $this->refundEvent('re_2', 800, 'succeeded');
        $this->assertSame([1000, 'refunded'], $this->state($tx));

        // A succeeded refund later reported failed/canceled (Stripe can do this) is taken back out.
        $this->refundEvent('re_2', 800, 'canceled');
        $this->refundEvent('re_1', 800, 'failed');
        $this->assertSame([0, 'succeeded'], $this->state($tx));
        $this->assertSame(0, $tx->refresh()->getRefundableAmount() - 1000);
    }

    public function test_charge_refunded_with_its_refund_list_converges_with_refund_events_in_any_order(): void
    {
        Event::fake([RefundProcessed::class]);
        $tx = $this->paid();

        $this->refundEvent('re_1', 300, 'succeeded');
        app(HandleStripeWebhook::class)->handle(new PaymentWebhookReceived(new WebhookPayload('evt_c', 'charge.refunded', 'stripe', [
            'id' => 'ch_1', 'object' => 'charge', 'payment_intent' => 'pi_1', 'customer' => 'cus_1', 'amount' => 1000, 'currency' => 'eur', 'refunded' => false,
            'refunds' => ['data' => [
                ['id' => 're_1', 'amount' => 300, 'currency' => 'eur', 'status' => 'succeeded', 'reason' => null],
                ['id' => 're_2', 'amount' => 200, 'currency' => 'eur', 'status' => 'pending', 'reason' => null],
            ]],
        ])));

        $this->assertSame([300, 'partially_refunded'], $this->state($tx));
        $this->assertSame(2, Refund::count());
        Event::assertDispatchedTimes(RefundProcessed::class, 1);
    }

    public function test_an_already_refunded_payment_stays_refunded_on_a_late_refund_event(): void
    {
        $tx = $this->paid();
        $this->refundEvent('re_1', 1000, 'succeeded');

        $this->refundEvent('re_1', 1000, 'succeeded');

        $this->assertSame([1000, 'refunded'], $this->state($tx));
    }

    public function test_a_failed_payment_is_not_turned_into_a_succeeded_one_by_refund_bookkeeping(): void
    {
        $tx = $this->paid();
        $tx->update(['status' => 'disputed']);

        $this->refundEvent('re_1', 200, 'failed');

        $this->assertSame([0, 'disputed'], $this->state($tx));
    }

    private function adminRefund(Transaction $tx, ?int $amount): ?string
    {
        $gateway = Mockery::mock(SupportsRefunds::class);
        Payment::swap(new class($gateway) {
            public function __construct(private object $gateway) {}

            public function driver(string $name): object
            {
                return $this->gateway;
            }
        });
        $gateway->shouldReceive('partialRefund')->andReturn(new \LaravelPlus\PaymentGateway\DTOs\Refund('re_x', 'pi_1', 'succeeded', (int) $amount, 'EUR', 'stripe'));
        $gateway->shouldReceive('refund')->andReturn(new \LaravelPlus\PaymentGateway\DTOs\Refund('re_x', 'pi_1', 'succeeded', 0, 'EUR', 'stripe'));

        session()->forget("error");
        $response = (new TransactionController())->refund(RefundTransactionRequest::create('/x', 'POST', $amount === null ? [] : ['amount' => $amount]), $tx);

        return $response->getSession()?->get('error');
    }

    public function test_admin_rejects_an_over_refund_and_can_refund_the_rest_of_a_partial(): void
    {
        $this->app['session']->start();
        $this->app['request']->setLaravelSession($this->app['session.store']);
        $tx = $this->paid();
        $this->refundEvent('re_1', 400, 'succeeded');

        $this->assertStringContainsString('exceeds', (string) $this->adminRefund($tx->refresh(), 700));
        $this->assertNull($this->adminRefund($tx->refresh(), 600));
    }
}

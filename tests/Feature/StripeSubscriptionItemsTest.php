<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Tests\Feature;

use LaravelPlus\PaymentGateway\DTOs\Customer;
use LaravelPlus\PaymentGateway\DTOs\Subscription;
use LaravelPlus\PaymentGateway\Drivers\StripeGateway;
use LaravelPlus\PaymentGateway\Exceptions\PaymentException;
use LaravelPlus\PaymentGateway\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Stripe\Exception\InvalidRequestException;
use Stripe\StripeClient;

/**
 * gh-24: a base Price plus a per-seat Price on one subscription. A fake client
 * records each call and answers with a synthetic subscription; nothing leaves the process.
 */
class StripeSubscriptionItemsTest extends TestCase
{
    /** @var list<array{0: string, 1: array, 2: array}> */
    private array $calls = [];

    /** Items the fake subscription answers with: [item id, price id, quantity, unit amount, interval]. */
    private array $items = [
        ['si_base', 'price_base', 1, 2000, 'month'],
        ['si_seat', 'price_seat', 3, 500, 'month'],
    ];

    private ?\Throwable $fail = null;

    private function gateway(): StripeGateway
    {
        $gateway = new StripeGateway(['secret' => 'sk_test_fake']);
        $test = $this;

        $fake = new class($test) extends StripeClient
        {
            public function __construct(private object $test)
            {
                parent::__construct('sk_test_fake');
            }

            public function __get($name)
            {
                return new class($this->test, $name)
                {
                    public function __construct(private object $test, private string $path) {}

                    public function __get($name)
                    {
                        return new self($this->test, $this->path.'.'.$name);
                    }

                    public function __call($method, $args)
                    {
                        return $this->test->answer($this->path.'.'.$method, $args);
                    }
                };
            }
        };

        (fn () => $this->client = $fake)->call($gateway);

        return $gateway;
    }

    /** Called by the fake client. */
    public function answer(string $call, array $args): object
    {
        $this->calls[] = [$call, str_ends_with($call, 'create') ? $args[0] : ($args[1] ?? []), str_ends_with($call, 'create') ? ($args[1] ?? []) : ($args[2] ?? [])];

        if ($this->fail !== null) {
            throw $this->fail;
        }

        if ($call === 'checkout.sessions.create') {
            return \Stripe\Checkout\Session::constructFrom(['id' => 'cs_1', 'url' => 'https://checkout.test', 'status' => 'open', 'customer' => 'cus_1', 'subscription' => null]);
        }

        return \Stripe\Subscription::constructFrom([
            'id' => 'sub_1', 'customer' => 'cus_1', 'status' => 'active', 'cancel_at_period_end' => false, 'canceled_at' => null,
            'current_period_start' => 1_700_000_000, 'current_period_end' => 1_702_592_000, 'trial_start' => null, 'trial_end' => null,
            'items' => ['object' => 'list', 'data' => array_map(fn ($i) => [
                'id' => $i[0], 'quantity' => $i[2],
                'price' => ['id' => $i[1], 'unit_amount' => $i[3], 'currency' => 'eur', 'recurring' => ['interval' => $i[4], 'interval_count' => 1]],
            ], $this->items)],
        ]);
    }

    private function customer(): Customer
    {
        return new Customer(id: 'cus_1', email: 'owner@example.com');
    }

    private function checkout(array $options = []): array
    {
        $this->gateway()->createCheckoutSession($this->customer(), 'price_base', 'https://app.test/ok', 'https://app.test/no', $options);

        return $this->calls[0][1];
    }

    #[Test]
    public function single_price_checkout_is_unchanged(): void
    {
        $this->assertSame([['price' => 'price_base', 'quantity' => 1]], $this->checkout()['line_items']);
    }

    #[Test]
    public function checkout_sends_the_base_price_and_extra_seat_items(): void
    {
        $params = $this->checkout(['quantity' => 1, 'items' => [['price_id' => 'price_seat', 'quantity' => 3]]]);

        $this->assertSame([['price' => 'price_base', 'quantity' => 1], ['price' => 'price_seat', 'quantity' => 3]], $params['line_items']);
    }

    #[Test]
    #[DataProvider('badItems')]
    public function invalid_items_never_reach_stripe(array $options): void
    {
        try {
            $this->checkout($options);
            $this->fail('accepted '.json_encode($options));
        } catch (PaymentException) {
            $this->assertSame([], $this->calls);
        }

        try {
            $this->gateway()->createSubscription($this->customer(), 'price_base', 'pm_1', $options);
            $this->fail('createSubscription accepted '.json_encode($options));
        } catch (PaymentException) {
            $this->assertSame([], $this->calls);
        }
    }

    public static function badItems(): array
    {
        return [
            'zero quantity' => [['quantity' => 0]],
            'string quantity' => [['quantity' => '3']],
            'zero seat quantity' => [['items' => [['price_id' => 'price_seat', 'quantity' => 0]]]],
            'float seat quantity' => [['items' => [['price_id' => 'price_seat', 'quantity' => 1.5]]]],
            'missing price id' => [['items' => [['quantity' => 2]]]],
            'empty price id' => [['items' => [['price_id' => '', 'quantity' => 2]]]],
            'client-supplied amount' => [['items' => [['price_id' => 'price_seat', 'quantity' => 2, 'unit_amount' => 1]]]],
            'price repeats the base' => [['items' => [['price_id' => 'price_base', 'quantity' => 2]]]],
            'items not a list' => [['items' => 'price_seat']],
        ];
    }

    #[Test]
    public function creates_a_subscription_with_every_item_and_returns_them_all(): void
    {
        $sub = $this->gateway()->createSubscription($this->customer(), 'price_base', 'pm_1', ['items' => [['price_id' => 'price_seat', 'quantity' => 3]]]);

        $this->assertSame([['price' => 'price_base', 'quantity' => 1], ['price' => 'price_seat', 'quantity' => 3]], $this->calls[0][1]['items']);
        $this->assertSeatSubscription($sub);
    }

    #[Test]
    public function reads_every_item_and_quantity_back(): void
    {
        $this->assertSeatSubscription($this->gateway()->getSubscription('sub_1'));
    }

    private function assertSeatSubscription(Subscription $sub): void
    {
        $this->assertSame('price_base', $sub->planId);
        $this->assertSame(2000 + 3 * 500, $sub->amount);
        $this->assertCount(2, $sub->items);
        $seat = $sub->itemForPrice('price_seat');
        $this->assertSame(['si_seat', 3, 500, 'month', 1], [$seat->id, $seat->quantity, $seat->unitAmount, $seat->interval, $seat->intervalCount]);
        $this->assertSame(3, $sub->toArray()['items'][1]['quantity']);
        $this->assertNull($sub->itemForPrice('price_other'));
    }

    #[Test]
    public function a_single_item_subscription_reads_as_before(): void
    {
        $this->items = [['si_base', 'price_base', 1, 2000, 'year']];

        $sub = $this->gateway()->getSubscription('sub_1');

        $this->assertSame(['price_base', 2000, 'EUR', 'year'], [$sub->planId, $sub->amount, $sub->currency, $sub->interval]);
        $this->assertCount(1, $sub->items);
    }

    #[Test]
    public function changing_seats_touches_only_the_seat_item(): void
    {
        $gateway = $this->gateway();

        foreach ([3, 1] as $seats) {
            $gateway->updateSubscriptionItems('sub_1', [['id' => 'si_seat', 'quantity' => $seats]], ['idempotency_key' => 'seats-'.$seats]);
        }

        $this->assertSame(['subscriptions.update', 'subscriptions.update'], array_column($this->calls, 0));
        [, $params, $opts] = $this->calls[0];
        $this->assertSame([['id' => 'si_seat', 'quantity' => 3]], $params['items']);
        $this->assertSame('create_prorations', $params['proration_behavior']);
        $this->assertSame(['idempotency_key' => 'seats-3'], $opts);
        $this->assertSame([['id' => 'si_seat', 'quantity' => 1]], $this->calls[1][1]['items']);
    }

    #[Test]
    public function adds_removes_and_reprices_items_by_id(): void
    {
        $this->gateway()->updateSubscriptionItems('sub_1', [
            ['price_id' => 'price_addon', 'quantity' => 2],
            ['id' => 'si_seat', 'deleted' => true],
            ['id' => 'si_base', 'price_id' => 'price_base_annual'],
        ], ['proration_behavior' => 'always_invoice']);

        $this->assertSame([
            ['price' => 'price_addon', 'quantity' => 2],
            ['id' => 'si_seat', 'deleted' => true],
            ['id' => 'si_base', 'price' => 'price_base_annual'],
        ], $this->calls[0][1]['items']);
        $this->assertSame('always_invoice', $this->calls[0][1]['proration_behavior']);
    }

    #[Test]
    #[DataProvider('badChanges')]
    public function invalid_item_changes_never_reach_stripe(array $changes, array $options = []): void
    {
        $this->expectException(PaymentException::class);

        try {
            $this->gateway()->updateSubscriptionItems('sub_1', $changes, $options);
        } finally {
            $this->assertSame([], $this->calls);
        }
    }

    public static function badChanges(): array
    {
        return [
            'nothing to change' => [[]],
            'item id without a change' => [[['id' => 'si_seat']]],
            'zero quantity' => [[['id' => 'si_seat', 'quantity' => 0]]],
            'new item without price' => [[['quantity' => 2]]],
            'client-supplied amount' => [[['id' => 'si_seat', 'quantity' => 2, 'unit_amount' => 1]]],
            'delete plus change' => [[['id' => 'si_seat', 'deleted' => true, 'quantity' => 2]]],
            'same item twice' => [[['id' => 'si_seat', 'quantity' => 2], ['id' => 'si_seat', 'quantity' => 3]]],
            'unknown proration' => [[['id' => 'si_seat', 'quantity' => 2]], ['proration_behavior' => 'sometimes']],
        ];
    }

    #[Test]
    public function plan_change_on_a_multi_item_subscription_refuses_to_guess_the_item(): void
    {
        try {
            $this->gateway()->updateSubscription('sub_1', 'price_pro');
            $this->fail('changed an arbitrary item');
        } catch (PaymentException) {
            $this->assertSame(['subscriptions.retrieve'], array_column($this->calls, 0));
        }
    }

    #[Test]
    public function a_failed_item_update_raises_and_reports_no_new_state(): void
    {
        $this->fail = InvalidRequestException::factory('No such price');

        $this->expectException(PaymentException::class);
        $this->gateway()->updateSubscriptionItems('sub_1', [['id' => 'si_seat', 'quantity' => 4]]);
    }
}

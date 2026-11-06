<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Tests\Feature;

use LaravelPlus\PaymentGateway\Contracts\TrialEligibility;
use LaravelPlus\PaymentGateway\DTOs\Customer;
use LaravelPlus\PaymentGateway\Drivers\StripeGateway;
use LaravelPlus\PaymentGateway\Exceptions\PaymentException;
use LaravelPlus\PaymentGateway\Tests\TestCase;
use Stripe\StripeClient;

/**
 * What the gateway sends to Stripe for trials. A fake client records each call;
 * nothing leaves the process.
 */
class StripeTrialGatewayTest extends TestCase
{
    /** @var array<int, array{0: string, 1: array}> */
    private array $calls = [];

    private function gateway(): StripeGateway
    {
        $gateway = new StripeGateway(['secret' => 'sk_test_fake']);
        $calls = &$this->calls;

        $fake = new class($calls) extends StripeClient
        {
            public function __construct(private array &$calls)
            {
                parent::__construct('sk_test_fake');
            }

            public function __get($name)
            {
                return new FakeStripeService($this->calls, $name);
            }
        };

        (fn () => $this->client = $fake)->call($gateway);

        return $gateway;
    }

    private function customer(): Customer
    {
        return new Customer(id: 'cus_1', email: 'owner@example.com');
    }

    private function checkout(array $options): array
    {
        $this->gateway()->createCheckoutSession($this->customer(), 'price_1', 'https://app.test/ok', 'https://app.test/no', $options);

        return $this->calls[0][1];
    }

    public function test_checkout_trial_with_end_behavior_and_card_optional(): void
    {
        $params = $this->checkout(['trial_days' => 7, 'trial_end_behavior' => 'pause', 'payment_method_collection' => 'if_required']);

        $this->assertSame('checkout.sessions.create', $this->calls[0][0]);
        $this->assertSame(
            ['trial_period_days' => 7, 'trial_settings' => ['end_behavior' => ['missing_payment_method' => 'pause']]],
            $params['subscription_data'],
        );
        $this->assertSame('if_required', $params['payment_method_collection']);
    }

    public function test_checkout_keeps_the_legacy_option_name(): void
    {
        $this->assertSame(['trial_period_days' => 14], $this->checkout(['trial_period_days' => 14])['subscription_data']);
    }

    public function test_checkout_without_trial_sends_no_subscription_data(): void
    {
        $this->assertArrayNotHasKey('subscription_data', $this->checkout(['trial_period_days' => 0]));
    }

    public function test_direct_subscription_uses_the_same_contract(): void
    {
        $this->gateway()->createSubscription($this->customer(), 'price_1', 'pm_1', ['trial_days' => 1, 'trial_end_behavior' => 'cancel']);

        [$method, $params] = $this->calls[0];
        $this->assertSame('subscriptions.create', $method);
        $this->assertSame(1, $params['trial_period_days']);
        $this->assertSame('cancel', $params['trial_settings']['end_behavior']['missing_payment_method']);
    }

    public function test_direct_subscription_without_trial_sends_no_trial_key(): void
    {
        $this->gateway()->createSubscription($this->customer(), 'price_1', 'pm_1');

        $this->assertArrayNotHasKey('trial_period_days', $this->calls[0][1]);
    }

    public function test_invalid_trial_never_reaches_stripe(): void
    {
        try {
            $this->checkout(['trial_days' => -3]);
            $this->fail('accepted a negative trial');
        } catch (PaymentException) {
            $this->assertSame([], $this->calls);
        }
    }

    public function test_host_eligibility_policy_sees_the_billing_owner_and_can_refuse(): void
    {
        $seen = [];
        $this->app->instance(TrialEligibility::class, new class($seen) implements TrialEligibility
        {
            public function __construct(private array &$seen) {}

            public function allowsTrial(Customer $customer, string $priceId, array $options): bool
            {
                $this->seen[] = [$customer->id, $priceId, $options['metadata']['org'] ?? null];

                return ($options['metadata']['org'] ?? null) !== 'org_used_trial';
            }
        });

        $this->checkout(['trial_days' => 14, 'metadata' => ['org' => 'org_new']]);
        $this->assertCount(1, $this->calls);

        try {
            $this->checkout(['trial_days' => 14, 'metadata' => ['org' => 'org_used_trial']]);
            $this->fail('policy was ignored');
        } catch (PaymentException) {
            $this->assertCount(1, $this->calls);
        }

        $this->assertSame([['cus_1', 'price_1', 'org_new'], ['cus_1', 'price_1', 'org_used_trial']], $seen);
    }

    public function test_switching_tier_during_trial_does_not_ask_for_a_new_trial(): void
    {
        $this->gateway()->updateSubscription('sub_1', 'price_pro');

        [$method, $params] = $this->calls[1];
        $this->assertSame('subscriptions.update', $method);
        $this->assertSame([[ 'id' => 'si_1', 'price' => 'price_pro']], $params['items']);
        $this->assertSame([], array_intersect(array_keys($params), ['trial_end', 'trial_period_days', 'trial_from_plan']));
    }
}

/**
 * Records "<service>.<method>" and the params, returns a minimal Stripe object.
 */
final class FakeStripeService
{
    public function __construct(private array &$calls, private string $path) {}

    public function __get($name)
    {
        return new self($this->calls, $this->path.'.'.$name);
    }

    public function __call($method, $args)
    {
        $this->calls[] = [$this->path.'.'.$method, $method === 'create' ? $args[0] : ($args[1] ?? [])];

        return match ($this->path) {
            'checkout.sessions' => \Stripe\Checkout\Session::constructFrom(['id' => 'cs_1', 'url' => 'https://checkout.test', 'status' => 'open', 'customer' => 'cus_1', 'subscription' => null]),
            default => \Stripe\Subscription::constructFrom([
                'id' => 'sub_1', 'customer' => 'cus_1', 'status' => 'trialing', 'cancel_at_period_end' => false, 'canceled_at' => null, 'ended_at' => null,
                'current_period_start' => time(), 'current_period_end' => time() + 86400,
                'trial_start' => time(), 'trial_end' => time() + 86400,
                'items' => ['object' => 'list', 'data' => [['id' => 'si_1', 'price' => ['id' => 'price_1', 'unit_amount' => 1000, 'currency' => 'eur', 'recurring' => ['interval' => 'month']]]]],
            ]),
        };
    }
}

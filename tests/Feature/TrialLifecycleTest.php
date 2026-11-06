<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Tests\Feature;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use LaravelPlus\PaymentGateway\DTOs\WebhookPayload;
use LaravelPlus\PaymentGateway\Enums\SubscriptionStatus;
use LaravelPlus\PaymentGateway\Events\PaymentWebhookReceived;
use LaravelPlus\PaymentGateway\Events\SubscriptionTrialEnded;
use LaravelPlus\PaymentGateway\Events\SubscriptionTrialStarted;
use LaravelPlus\PaymentGateway\Events\SubscriptionTrialWillEnd;
use LaravelPlus\PaymentGateway\Listeners\HandleStripeWebhook;
use LaravelPlus\PaymentGateway\Mail\SubscriptionTrialEndingMail;
use LaravelPlus\PaymentGateway\Models\PaymentCustomer;
use LaravelPlus\PaymentGateway\Models\Subscription;
use LaravelPlus\PaymentGateway\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class TrialLifecycleTest extends TestCase
{
    private int $eventSeq = 0;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('payment-gateway.billable_model', User::class);
        $app['config']->set('payment-gateway.notifications.trial_reminder_days', [3, 1]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::forceCreate(['name' => 'A', 'email' => 'a@example.com', 'password' => 'x']);
        PaymentCustomer::create(['user_id' => $user->id, 'stripe_id' => 'cus_1', 'email' => $user->email]);
    }

    /** Every delivery gets a new event id, like a Stripe resend of the same state. */
    private function webhook(string $type, string $status, int $trialEndInSeconds = 86400): void
    {
        app(HandleStripeWebhook::class)->handle(new PaymentWebhookReceived(new WebhookPayload(
            'evt_'.++$this->eventSeq,
            $type,
            'stripe',
            [
                'id' => 'sub_1', 'object' => 'subscription', 'customer' => 'cus_1', 'status' => $status, 'currency' => 'eur',
                'trial_start' => now()->getTimestamp(), 'trial_end' => now()->getTimestamp() + $trialEndInSeconds,
                'items' => ['data' => [['price' => ['id' => 'price_1', 'unit_amount' => 1000, 'recurring' => ['interval' => 'month']]]]],
            ],
        )));
    }

    private function remind(): void
    {
        $this->artisan('payment-gateway:trial-reminders')->assertSuccessful();
    }

    public function test_trial_start_fires_once_across_redeliveries(): void
    {
        Event::fake([SubscriptionTrialStarted::class]);

        $this->webhook('customer.subscription.created', 'trialing');
        $this->webhook('customer.subscription.created', 'trialing');
        $this->webhook('customer.subscription.updated', 'trialing');

        Event::assertDispatchedTimes(SubscriptionTrialStarted::class, 1);
    }

    #[DataProvider('outcomes')]
    public function test_trial_end_fires_once_with_the_outcome(string $stripeStatus, SubscriptionStatus $expected): void
    {
        Event::fake([SubscriptionTrialEnded::class]);

        $this->webhook('customer.subscription.created', 'trialing');
        $this->webhook('customer.subscription.updated', $stripeStatus);
        // Late, out-of-order copy of the old state, then the new state again.
        $this->webhook('customer.subscription.updated', 'trialing');
        $this->webhook('customer.subscription.updated', $stripeStatus);

        Event::assertDispatchedTimes(SubscriptionTrialEnded::class, 1);
        Event::assertDispatched(SubscriptionTrialEnded::class, fn ($e) => $e->subscription->status === $expected);
    }

    public static function outcomes(): array
    {
        return [
            'converted, first invoice paid' => ['active', SubscriptionStatus::Active],
            'first charge failed' => ['past_due', SubscriptionStatus::PastDue],
            'no card, end_behavior=pause' => ['paused', SubscriptionStatus::Paused],
            'no card, end_behavior=cancel' => ['canceled', SubscriptionStatus::Canceled],
        ];
    }

    public function test_cancel_during_trial_via_deleted_event_ends_the_trial_once(): void
    {
        Event::fake([SubscriptionTrialEnded::class]);

        $this->webhook('customer.subscription.created', 'trialing');
        $this->webhook('customer.subscription.deleted', 'canceled');
        $this->webhook('customer.subscription.deleted', 'canceled');

        Event::assertDispatchedTimes(SubscriptionTrialEnded::class, 1);
        $this->assertFalse(Subscription::first()->onTrial());
    }

    public function test_one_day_trial_gets_exactly_one_reminder_never_negative(): void
    {
        Event::fake([SubscriptionTrialWillEnd::class]);

        $this->webhook('customer.subscription.created', 'trialing', 86400);
        // Stripe's trial_will_end arrives immediately for trials under 3 days.
        $this->webhook('customer.subscription.trial_will_end', 'trialing', 86400);
        $this->remind();
        $this->travel(12)->hours();
        $this->remind();
        $this->travel(13)->hours(); // past trial_end
        $this->remind();

        Event::assertDispatchedTimes(SubscriptionTrialWillEnd::class, 1);
        Event::assertDispatched(SubscriptionTrialWillEnd::class, fn ($e) => $e->daysRemaining === 1);
    }

    public function test_seven_day_trial_gets_one_reminder_per_lead(): void
    {
        Event::fake([SubscriptionTrialWillEnd::class]);

        $this->webhook('customer.subscription.created', 'trialing', 7 * 86400);
        $this->remind(); // 7 days left, nothing due
        $this->travel(4 * 24 + 1)->hours();
        $this->remind();
        $this->remind();
        $this->webhook('customer.subscription.trial_will_end', 'trialing', 3 * 86400 - 3600);
        $this->travel(2 * 24 + 12)->hours();
        $this->remind();
        $this->remind();

        $days = [];
        Event::assertDispatched(SubscriptionTrialWillEnd::class, function ($e) use (&$days) {
            $days[] = $e->daysRemaining;

            return true;
        });
        $this->assertSame([3, 1], $days);
    }

    public function test_extended_trial_is_reminded_again_for_its_new_end(): void
    {
        Event::fake([SubscriptionTrialWillEnd::class]);

        $this->webhook('customer.subscription.created', 'trialing', 2 * 86400);
        $this->remind();
        $this->webhook('customer.subscription.updated', 'trialing', 10 * 86400); // extended
        $this->remind();
        $this->travel(8)->days();
        $this->remind();

        Event::assertDispatchedTimes(SubscriptionTrialWillEnd::class, 2);
    }

    public function test_reminder_mail_goes_out_once_through_the_existing_notification(): void
    {
        Mail::fake();

        $this->webhook('customer.subscription.created', 'trialing', 86400);
        $this->remind();
        $this->remind();

        Mail::assertQueued(SubscriptionTrialEndingMail::class, 1);
    }

    public function test_reminder_mail_can_be_switched_off_while_the_event_still_fires(): void
    {
        Mail::fake();
        config(['payment-gateway.notifications.trial_ending' => false]);
        $fired = 0;
        Event::listen(SubscriptionTrialWillEnd::class, function () use (&$fired) {
            $fired++;
        });

        $this->webhook('customer.subscription.created', 'trialing', 86400);
        $this->remind();

        Mail::assertNotQueued(SubscriptionTrialEndingMail::class);
        $this->assertSame(1, $fired);
    }
}

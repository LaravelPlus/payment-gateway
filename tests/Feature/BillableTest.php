<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LaravelPlus\PaymentGateway\Models\PaymentCustomer;
use LaravelPlus\PaymentGateway\Models\Subscription;
use LaravelPlus\PaymentGateway\Tests\TestCase;
use LaravelPlus\PaymentGateway\Traits\Billable;

class BillableTest extends TestCase
{
    public function test_payment_customer_relations_resolve_a_model(): void
    {
        $user = new class extends Model
        {
            use Billable;

            protected $table = 'users';
        };

        $this->assertInstanceOf(HasMany::class, $user->paymentCustomers());
        $this->assertInstanceOf(PaymentCustomer::class, $user->paymentCustomers()->getRelated());
        $this->assertInstanceOf(PaymentCustomer::class, $user->paymentCustomer()->getRelated());
    }

    public function test_past_due_keeps_access_only_within_the_grace_period(): void
    {
        $user = new class extends Model
        {
            use Billable;

            protected $table = 'users';

            protected $guarded = [];
        };
        $user = $user->forceCreate(['name' => 'A', 'email' => 'a@example.com', 'password' => 'x']);

        $subscription = Subscription::forceCreate([
            'user_id' => $user->id, 'driver' => 'stripe', 'provider_id' => 'sub_1', 'provider_plan_id' => 'price_1',
            'plan_id' => 'pro', 'status' => 'past_due', 'amount' => 900, 'currency' => 'EUR', 'interval' => 'month',
            'interval_count' => 1, 'quantity' => 1, 'current_period_start' => now()->subDays(3),
        ]);

        $this->assertFalse($user->subscribed());

        config()->set('payment-gateway.past_due_grace_days', 7);
        $this->assertTrue($user->subscribed());

        $subscription->forceFill(['current_period_start' => now()->subDays(8)])->save();
        $this->assertFalse($user->subscribed());
    }
}

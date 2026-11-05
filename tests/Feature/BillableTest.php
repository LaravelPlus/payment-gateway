<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LaravelPlus\PaymentGateway\Models\PaymentCustomer;
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
}

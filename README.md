# Payment Gateway for Laravel

A flexible multi-provider payment gateway package for Laravel with support for Stripe, PayPal, Crypto, Bank Transfer, and Cash on Delivery.

## Features

- **Multiple Payment Drivers**: Stripe, PayPal, Coinbase Commerce, Bank Transfer, Cash on Delivery
- **Subscriptions**: Full subscription lifecycle management with trials and grace periods
- **Refunds**: Full and partial refund support
- **Webhooks**: Automatic webhook handling for all providers
- **Customer Management**: Store customer data with multiple addresses
- **All amounts in cents**: Integer-based amounts for precision
- **Plans**: Database-driven subscription plans with free tier support
- **Invoices**: Automatic invoice generation with PDF support (DomPDF/Browsershot)
- **Email Notifications**: Payment receipts, subscription confirmations, trial reminders
- **Events**: Laravel events for all payment lifecycle hooks
- **Factories**: Full factory support for testing
- **Artisan Commands**: Install, sync plans, trial reminders, cleanup

## Requirements

- PHP 8.2+
- Laravel 11.x or 12.x

## Installation

### Quick Install (Recommended)

```bash
# 1. Copy package to your Laravel project
mkdir -p packages/laravelplus
cp -r /path/to/payment-gateway packages/laravelplus/

# 2. Run the install script
./packages/laravelplus/payment-gateway/install.sh
```

The install script will:
- Add the package to composer.json
- Run composer update
- Run the interactive installer

### Manual Installation

#### Step 1: Copy the package

```bash
mkdir -p packages/laravelplus
cp -r /path/to/payment-gateway packages/laravelplus/
```

#### Step 2: Add to composer.json

Since this is a local package (not on Packagist), add a repository entry:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "packages/laravelplus/payment-gateway",
            "options": {
                "symlink": true
            }
        }
    ],
    "require": {
        "laravelplus/payment-gateway": "@dev"
    }
}
```

#### Step 3: Install with Composer

```bash
composer update laravelplus/payment-gateway
```

#### Step 4: Run the installer command

```bash
php artisan payment-gateway:install
```

This will interactively:
- Publish config and migrations
- Run migrations
- Add Billable trait to User model
- Seed default subscription plans
- Add environment variables

#### Alternative: Manual setup

If you prefer manual control:

```bash
# Publish config
php artisan vendor:publish --tag=payment-gateway-config

# Publish and run migrations
php artisan vendor:publish --tag=payment-gateway-migrations
php artisan migrate
```

Then add to your User model:

```php
use LaravelPlus\PaymentGateway\Traits\Billable;

class User extends Authenticatable
{
    use Billable;
}
```

### Configure environment variables

Add to your `.env`:

```env
# Payment Gateway
PAYMENT_DRIVER=stripe
PAYMENT_CURRENCY=EUR

# Stripe
STRIPE_KEY=pk_test_xxx
STRIPE_SECRET=sk_test_xxx
STRIPE_WEBHOOK_SECRET=whsec_xxx

# PayPal
PAYPAL_CLIENT_ID=
PAYPAL_CLIENT_SECRET=
PAYPAL_MODE=sandbox

# Subscriptions
SUBSCRIPTION_TRIAL_DAYS=14
SUBSCRIPTION_ALLOW_FREE=true
```

## Usage

### Basic Payment

```php
use LaravelPlus\PaymentGateway\Facades\Payment;

// Create a payment intent
$intent = Payment::createPaymentIntent(
    amount: 1999, // $19.99 in cents
    currency: 'USD',
    metadata: ['order_id' => 'ORD-123']
);

// Charge using a payment method
$result = Payment::charge(
    amount: 1999,
    currency: 'USD',
    paymentMethodId: 'pm_xxx'
);

if ($result->status->isSuccessful()) {
    // Payment successful
}
```

### Using Different Drivers

```php
// Stripe (default)
Payment::driver('stripe')->charge(...);

// PayPal
Payment::driver('paypal')->createPaymentIntent(...);

// Cash on Delivery
Payment::driver('cash_on_delivery')->charge(...);

// Bank Transfer
Payment::driver('bank_transfer')->charge(...);
```

### Subscriptions (Stripe)

```php
use LaravelPlus\PaymentGateway\Facades\Payment;

$stripe = Payment::driver('stripe');

// Create a subscription
$subscription = $stripe->createSubscription($customer, 'price_xxx', 'pm_xxx', [
    'trial_days' => 14,
]);

// Cancel subscription
$stripe->cancelSubscription($subscription->id);
```

### Free trials

A trial is a Stripe subscription-wide free period. It is independent of the Price,
so one Price serves a 1, 7, 14, 30 or 40-day trial. No trial is added unless you ask for one.

```php
// Checkout: card optional, subscription paused if none was added by the end
$session = $stripe->createCheckoutSession($customer, 'price_xxx', $successUrl, $cancelUrl, [
    'trial_days' => 7,
    'trial_end_behavior' => 'pause',          // cancel | pause | create_invoice
    'payment_method_collection' => 'if_required', // or 'always' to take the card up front
]);

// Or an exact end date instead of a length (Checkout needs it at least 48h out)
$stripe->createSubscription($customer, 'price_xxx', 'pm_xxx', ['trial_end' => now()->addDays(30)]);
```

| Option | Values |
| --- | --- |
| `trial_days` | whole number, 1 to `subscriptions.max_trial_days` (max 730). `null`/`0` = no trial |
| `trial_end` | `DateTimeInterface` or unix timestamp; not together with `trial_days` |
| `trial_end_behavior` | `cancel`, `pause`, `create_invoice`. Default `subscriptions.trial_end_behavior`, else Stripe's default `create_invoice` |
| `payment_method_collection` | Checkout only: `always`, `if_required` |

Invalid values throw `PaymentException` before Stripe is called.
`trial_period_days` (the old Checkout option) still works but is deprecated; use `trial_days`.
Changing plan with `updateSubscription()` never starts a new trial.

Don't grant access on the Checkout success redirect. Grant it from the synced
subscription (`$user->subscribed()`, `Subscription::onTrial()`) or from the events below.

**Who may start a trial** is your rule, not the package's. Bind a `TrialEligibility`
and every trial request is checked before it reaches Stripe. The `Customer` is the
billing owner, so "one trial per company" works when one user has several:

```php
use LaravelPlus\PaymentGateway\Contracts\TrialEligibility;
use LaravelPlus\PaymentGateway\DTOs\Customer;

final class OneTrialPerCompany implements TrialEligibility
{
    public function allowsTrial(Customer $customer, string $priceId, array $options): bool
    {
        return ! Company::where('stripe_id', $customer->id)->value('trial_used');
    }
}

// AppServiceProvider::register()
$this->app->bind(TrialEligibility::class, OneTrialPerCompany::class);
```

A refused trial throws `PaymentException`.

**Trial events**, each dispatched once even when Stripe redelivers webhooks:

- `SubscriptionTrialStarted` - subscription entered a trial
- `SubscriptionTrialWillEnd` - a reminder lead (`notifications.trial_reminder_days`, default `[3, 1]`) was reached; has `daysRemaining`
- `SubscriptionTrialEnded` - trial is over; `$event->subscription->status` is `Active` (first invoice created), `Canceled` or `Paused`. On Stripe a failed first charge arrives afterwards as a separate `active → past_due` update; read the synced status later, not at this event

Reminders come from Stripe's `trial_will_end` webhook and the `payment-gateway:trial-reminders`
command, sharing one ledger. When several leads are due at once (a 1-day trial with
`[3, 1]`) only the nearest is sent. The package mails `SubscriptionTrialEndingMail` unless
`notifications.trial_ending` is off; listen to the event to use your own channel instead.

Stripe's newer *Trial Offers* (paid or discounted intro periods, per item) are a
different model and not supported yet.

### Using Billable Trait

```php
// Charge a user
$user->charge(1999, 'pm_xxx', [
    'description' => 'One-time purchase'
]);

// Check subscription
if ($user->subscribed('pro')) {
    // User has active pro subscription
}

// Get payment customer
$customer = $user->paymentCustomer('stripe');
```

### Plans

```php
use LaravelPlus\PaymentGateway\Models\Plan;

// Get all public plans for pricing page
$plans = Plan::forPricingPage();

// Get specific plan
$plan = Plan::findBySlug('pro');

// Check plan properties
$plan->isFree();           // true if amount = 0
$plan->hasTrial();         // true if trial_days > 0
$plan->billing_description; // "$19.99 / month" or "Free"
$plan->formatted_price;     // "$19.99"

// Scopes
Plan::free()->get();        // Free plans only
Plan::paid()->get();        // Paid plans only
Plan::monthly()->get();     // Monthly plans
Plan::yearly()->get();      // Yearly plans
```

### Webhooks

Webhook routes are automatically registered at:
- `POST /webhooks/payment/stripe`
- `POST /webhooks/payment/paypal`
- `POST /webhooks/payment/crypto`

Responses tell the provider whether to retry:

| Status | Meaning | Provider retries? |
|---|---|---|
| 200 | Handled, or a duplicate of an event already handled | No |
| 400 | Not a JSON object, or signed but not a well-formed event for that provider | No |
| 401 | Signature missing or invalid | Provider-dependent |
| 413 | Body over 1 MB (measured on the real body, not `Content-Length`) | No |
| 415 | Not `application/json` | No |
| 500 | Handler or listener threw; the event claim is rolled back so the retry is processed | Yes |

Also cap the body in your web server (`client_max_body_size`, `post_max_size`); PHP has already read it by the time the controller runs.

**Stripe invoices.** Subscribe the Stripe endpoint to `invoice.finalized`, `invoice.paid`,
`invoice.payment_failed`, `invoice.voided` and `invoice.marked_uncollectible`. Each Stripe invoice
keeps exactly one local invoice, whatever order its events arrive in. Each sync holds a per-invoice
`Cache::lock`, so use a lock-capable cache shared by all workers (redis, database or memcached; the wait
is `payment-gateway.webhooks.lock_wait`, default 10 seconds). Paid and void are final, so a late
`finalized` event never reopens one or overwrites its amounts. The PDF is generated once, when the invoice is paid.
Invoices for a Stripe customer that matches no local user are not stored. Each one is logged as a
warning (`Stripe invoice for an unknown customer`) so it can be reconciled.

Listen to events in your `EventServiceProvider`:

```php
use LaravelPlus\PaymentGateway\Events\PaymentSucceeded;
use LaravelPlus\PaymentGateway\Events\SubscriptionCreated;

protected $listen = [
    PaymentSucceeded::class => [
        ProcessPayment::class,
    ],
    SubscriptionCreated::class => [
        ActivateSubscription::class,
    ],
];
```

### Available Events

- `PaymentSucceeded` - Payment was successful
- `PaymentFailed` - Payment failed
- `SubscriptionCreated` - New subscription created
- `SubscriptionCanceled` - Subscription was canceled
- `SubscriptionTrialStarted`, `SubscriptionTrialWillEnd`, `SubscriptionTrialEnded` - see [Free trials](#free-trials)
- `RefundProcessed` - Refund was processed
- `PaymentWebhookReceived` - Webhook received (before processing)
- `WebhookHandled` - Webhook successfully processed
- `WebhookHandleFailed` - Webhook processing failed

## Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag=payment-gateway-config
```

Key configuration options:

```php
return [
    'default' => env('PAYMENT_DRIVER', 'stripe'),
    'currency' => env('PAYMENT_CURRENCY', 'EUR'),

    'subscriptions' => [
        'default_trial_days' => 14,
        'allow_free_plans' => true,
        'grace_period_days' => 0,
    ],

    'drivers' => [
        'stripe' => [...],
        'paypal' => [...],
        'crypto' => [...],
        'bank_transfer' => [...],
        'cash_on_delivery' => [...],
    ],
];
```

### Invoices

Invoices are automatically generated for successful payments:

```php
use LaravelPlus\PaymentGateway\Models\Invoice;

// Get user invoices
$invoices = $user->invoices()->paid()->get();

// Get invoice PDF URL
$invoice->getPdfUrl();

// Check if PDF exists
$invoice->hasPdf();

// Manual invoice creation
$invoice = Invoice::create([
    'user_id' => $user->id,
    'subtotal' => 1999, // cents
    'total' => 1999,
    'amount_due' => 1999,
    'currency' => 'USD',
    'line_items' => [
        ['description' => 'Pro Plan', 'quantity' => 1, 'unit_price' => 1999, 'amount' => 1999]
    ],
]);

// Generate PDF
app(InvoicePdfGenerator::class)->generate($invoice);
```

### Email Notifications

The package sends automatic email notifications for:

- **Payment Receipt**: Sent after successful payment
- **Payment Failed**: Sent when payment fails
- **Subscription Created**: Welcome email for new subscriptions
- **Subscription Canceled**: Confirmation of cancellation
- **Refund Processed**: Confirmation of refund
- **Trial Ending**: Reminder before trial expires

Configure notifications in `config/payment-gateway.php`:

```php
'notifications' => [
    'payment_receipt' => true,
    'payment_failed' => true,
    'subscription_created' => true,
    'subscription_canceled' => true,
    'refund_processed' => true,
    'trial_ending' => true,
],
```

### Artisan Commands

```bash
# Install the package interactively
php artisan payment-gateway:install

# Sync local plans to Stripe/PayPal
php artisan payment-gateway:sync-plans --driver=stripe

# Dispatch trial-ending reminders that are due (leads from notifications.trial_reminder_days)
php artisan payment-gateway:trial-reminders

# Cleanup expired subscriptions
php artisan payment-gateway:cleanup-subscriptions
```

Add to your scheduler (`app/Console/Kernel.php` or `routes/console.php`):

```php
// Hourly so 1-day trials are reminded in time; each reminder is sent once however often it runs.
Schedule::command('payment-gateway:trial-reminders')->hourly();
Schedule::command('payment-gateway:cleanup-subscriptions')->daily();
```

## Database Schema

The package creates these tables:

- `payment_customers` - Customer records per provider
- `payment_transactions` - All payment transactions
- `payment_subscriptions` - Active subscriptions
- `payment_plans` - Subscription plans
- `payment_methods` - Stored payment methods
- `payment_refunds` - Refund records
- `payment_invoices` - Invoice records with PDF support

## Testing

The package includes factories for all models:

```php
use LaravelPlus\PaymentGateway\Models\Transaction;
use LaravelPlus\PaymentGateway\Models\Subscription;
use LaravelPlus\PaymentGateway\Models\Plan;
use LaravelPlus\PaymentGateway\Models\Invoice;

// Create a successful transaction
$transaction = Transaction::factory()->succeeded()->stripe()->create();

// Create a subscription on trial
$subscription = Subscription::factory()->trialing()->create();

// Create a paid plan with trial
$plan = Plan::factory()->monthly()->withTrial(14)->create();

// Create a paid invoice
$invoice = Invoice::factory()->paid()->withLineItems(3)->create();
```

## Customizing Views

Publish views to customize email templates and invoice PDF:

```bash
php artisan vendor:publish --tag=payment-gateway-views
```

Views are published to `resources/views/vendor/payment-gateway/`.

## License

MIT License

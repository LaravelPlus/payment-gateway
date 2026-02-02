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
mkdir -p packages/nejcc
cp -r /path/to/payment-gateway packages/nejcc/

# 2. Run the install script
./packages/nejcc/payment-gateway/install.sh
```

The install script will:
- Add the package to composer.json
- Run composer update
- Run the interactive installer

### Manual Installation

#### Step 1: Copy the package

```bash
mkdir -p packages/nejcc
cp -r /path/to/payment-gateway packages/nejcc/
```

#### Step 2: Add to composer.json

Since this is a local package (not on Packagist), add a repository entry:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "packages/nejcc/payment-gateway",
            "options": {
                "symlink": true
            }
        }
    ],
    "require": {
        "nejcc/payment-gateway": "@dev"
    }
}
```

#### Step 3: Install with Composer

```bash
composer update nejcc/payment-gateway
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
use Nejcc\PaymentGateway\Traits\Billable;

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
use Nejcc\PaymentGateway\Facades\Payment;

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
use Nejcc\PaymentGateway\Facades\Payment;

$stripe = Payment::driver('stripe');

// Create a subscription
$subscription = $stripe->createSubscription(
    customerId: 'cus_xxx',
    priceId: 'price_xxx',
    trialDays: 14
);

// Cancel subscription
$stripe->cancelSubscription($subscription->id);
```

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
use Nejcc\PaymentGateway\Models\Plan;

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

Listen to events in your `EventServiceProvider`:

```php
use Nejcc\PaymentGateway\Events\PaymentSucceeded;
use Nejcc\PaymentGateway\Events\SubscriptionCreated;

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
use Nejcc\PaymentGateway\Models\Invoice;

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

# Send trial ending reminders (run daily via scheduler)
php artisan payment-gateway:trial-reminders --days=3

# Cleanup expired subscriptions
php artisan payment-gateway:cleanup-subscriptions
```

Add to your scheduler (`app/Console/Kernel.php` or `routes/console.php`):

```php
Schedule::command('payment-gateway:trial-reminders --days=3')->daily();
Schedule::command('payment-gateway:trial-reminders --days=1')->daily();
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
use Nejcc\PaymentGateway\Models\Transaction;
use Nejcc\PaymentGateway\Models\Subscription;
use Nejcc\PaymentGateway\Models\Plan;
use Nejcc\PaymentGateway\Models\Invoice;

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

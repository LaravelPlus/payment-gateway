---
name: payment-gateway-development
description: >-
  Activate when working with the nejcc/payment-gateway package — using the
  Payment facade, Billable trait, creating payment drivers, managing
  transactions, subscriptions, plans, invoices, refunds, webhooks, or building
  admin pages under admin/payments.
---

# Payment Gateway Development

Package: `nejcc/payment-gateway` — Location: `packages/nejcc/payment-gateway/`
Namespace: `Nejcc\PaymentGateway` — Facade: `Payment` — Config: `payment-gateway`

## When to Apply

- Using `Payment::` facade or `Billable` trait methods
- Creating or modifying payment drivers, controllers, models, or migrations in the package
- Working with transactions, subscriptions, plans, invoices, refunds, or payment customers
- Handling webhooks from Stripe, PayPal, or other providers
- Building admin UI pages under `admin/payments`
- Writing tests for payment functionality
- Running payment Artisan commands

## Manager Pattern

Uses Laravel's `Manager` class via `PaymentGatewayManager`. Access drivers through the facade:

<code-snippet name="Payment Facade Usage" lang="php">
use Nejcc\PaymentGateway\Facades\Payment;

// Default driver (from config)
Payment::charge($amount, $currency, $paymentMethodId);

// Specific driver
Payment::driver('stripe')->createPaymentIntent(1000, 'EUR');
Payment::stripe()->createSubscription($planId, $customerId);
Payment::paypal()->charge(500, 'USD', $methodId);

// From request input
Payment::fromRequest()->charge(...);

// Inspect drivers
Payment::getAvailableDrivers();
Payment::hasDriver('crypto');
</code-snippet>

## Core Contract — `PaymentGatewayContract`

Every driver implements:
- `getName()`, `getDisplayName()`, `isAvailable()`
- `getSupportedCurrencies()`, `supportsCurrency(string $currency)`
- `createPaymentIntent(int $amount, string $currency, ?Customer $customer, array $metadata)`
- `charge(int $amount, string $currency, string $paymentMethodId, array $options)`
- `getPayment(string $transactionId)`, `cancel(string $transactionId)`

## Capability Contracts

Drivers optionally implement:
- **`SupportsCustomers`** — `createCustomer()`, `getCustomer()`, `updateCustomer()`, `deleteCustomer()`, `attachPaymentMethod()`, `detachPaymentMethod()`, `getPaymentMethods()`, `setDefaultPaymentMethod()`
- **`SupportsSubscriptions`** — `createPlan()`, `getPlan()`, `createSubscription()`, `getSubscription()`, `cancelSubscription()`, `pauseSubscription()`, `resumeSubscription()`, `updateSubscription()`
- **`SupportsRefunds`** — `refund()`, `partialRefund()`, `getRefund()`, `getRefundsForTransaction()`
- **`SupportsWebhooks`** — `verifyWebhookSignature()`, `parseWebhook()`, `getWebhookSecret()`, `handleWebhook()`

## Available Drivers

`stripe`, `paypal`, `crypto`, `bank_transfer`, `cash_on_delivery`

Config: `payment-gateway.default` (env: `PAYMENT_DRIVER`, default: `stripe`).

### Creating a New Driver

<code-snippet name="New Payment Driver" lang="php">
// src/Drivers/MyDriver.php
final class MyDriver implements PaymentGatewayContract, SupportsRefunds
{
    public function __construct(private readonly array $config) {}

    public function getName(): string { return 'my_driver'; }
    public function getDisplayName(): string { return 'My Driver'; }
    public function isAvailable(): bool { return ! empty($this->config['api_key']); }
    // ... implement remaining contract methods
}

// Register in PaymentGatewayManager:
public function createMyDriverDriver(): PaymentGatewayContract
{
    return new MyDriver($this->config->get('payment-gateway.drivers.my_driver', []));
}
</code-snippet>

## Billable Trait

Applied to `User` model. Provides:

<code-snippet name="Billable Trait Methods" lang="php">
use Nejcc\PaymentGateway\Traits\Billable;

// Relationships
$user->paymentCustomers();     // HasMany
$user->paymentCustomer();      // HasOne (primary)
$user->transactions();         // HasMany
$user->subscriptions();        // HasMany

// Customer management
$user->createAsPaymentCustomer($driver, $options);
$user->getOrCreatePaymentCustomer($driver, $options);
$user->hasPaymentCustomerId($driver);
$user->getPaymentCustomerId($driver);
$user->setPaymentCustomerId($customerId, $driver);

// Billing
$user->charge(1000, $paymentMethodId, $options);
$user->subscribed($plan);
$user->onTrial($plan);
$user->subscription($plan);
</code-snippet>

## Models

| Model | Key Relationships | Key Methods |
|-------|-------------------|-------------|
| `Transaction` | `user()`, `paymentCustomer()`, `payable()` (morph), `refunds()` | `isSuccessful()`, `isPending()`, `isFailed()`, `isRefunded()`, `toDto()` |
| `Subscription` | `user()`, `paymentCustomer()`, `plan()` | `isActive()`, `onTrial()`, `isCanceled()`, `onGracePeriod()`, `daysRemaining()`, `toDto()` |
| `Plan` | `subscriptions()` | `hasFeature()`, `getLimit()`, `isFree()`, `isPaid()`, `hasTrial()`, `findBySlug()`, `forPricingPage()` |
| `PaymentCustomer` | `user()`, `transactions()`, `subscriptions()`, `paymentMethods()` | `getProviderId()`, `setProviderId()`, `toDto()` |
| `PaymentMethod` | `user()`, `paymentCustomer()` | `isCard()`, `isExpired()`, `getDisplayName()` |
| `Invoice` | `user()`, `paymentCustomer()`, `subscription()`, `transaction()` | `markAsPaid()`, `markAsVoid()`, `finalize()`, `addLineItem()`, `generateInvoiceNumber()` |
| `Refund` | `transaction()`, `user()` | `isSuccessful()`, `isPending()`, `isFailed()`, `toDto()` |

`Plan` uses `HasUuids` and `SoftDeletes`. All models have factories.

## Enums

| Enum | Cases |
|------|-------|
| `PaymentStatus` | `Pending`, `Processing`, `RequiresAction`, `RequiresCapture`, `Succeeded`, `Failed`, `Canceled`, `Refunded`, `PartiallyRefunded`, `Disputed`, `Expired` |
| `PaymentDriver` | `Stripe`, `PayPal`, `Crypto`, `BankTransfer`, `CashOnDelivery` |
| `SubscriptionStatus` | `Active`, `Trialing`, `PastDue`, `Paused`, `Canceled`, `Unpaid`, `Incomplete`, `IncompleteExpired` |
| `InvoiceStatus` | `Draft`, `Open`, `Paid`, `Void`, `Uncollectible` |

All enums have `label()` and `color()` helper methods.

## Admin Routes

Prefix: `admin/payments` — Middleware: `['web', 'auth', 'role:super-admin,admin']`

- Dashboard: `GET /`, `GET /stats`
- Transactions: `GET /transactions`, `GET /transactions/{id}`, `POST /transactions/{id}/refund`
- Subscriptions: `GET /subscriptions`, `GET /subscriptions/{id}`, `POST /subscriptions/{id}/cancel`, `POST /subscriptions/{id}/resume`
- Customers: `GET /customers`, `GET /customers/{id}`
- Plans: full CRUD + `POST /plans/{id}/sync`
- Invoices: `GET /invoices`, `GET /invoices/{id}`, `GET /invoices/{id}/download`, `POST /invoices/{id}/regenerate-pdf`

## Events

- `PaymentSucceeded`, `PaymentFailed` — carry `PaymentResult`
- `SubscriptionCreated`, `SubscriptionCanceled` — carry `Subscription` DTO
- `PaymentWebhookReceived`, `WebhookHandled`, `WebhookHandleFailed` — webhook lifecycle
- `RefundProcessed`

## Artisan Commands

- `payment-gateway:install` — Publish config/migrations, add Billable trait, seed plans
- `payment-gateway:sync-plans` — Sync plans with Stripe/PayPal (`--driver=stripe --dry-run`)
- `payment-gateway:trial-reminders` — Send trial ending emails (`--days=3`)
- `payment-gateway:cleanup-subscriptions` — Mark expired subscriptions (`--dry-run`)

## Configuration

Key config paths in `payment-gateway.php`:

| Key | Default | Description |
|-----|---------|-------------|
| `default` | `stripe` (env: `PAYMENT_DRIVER`) | Default payment driver |
| `currency` | `EUR` (env: `PAYMENT_CURRENCY`) | Default currency |
| `drivers.*` | — | Per-driver credentials and options |
| `subscriptions.trial_days` | — | Default trial period |
| `webhooks.path` | — | Webhook endpoint path |
| `tables.*` | — | Database table name overrides |
| `models.*` | — | Custom model class overrides |
| `billable_model` | `App\Models\User` | Model using Billable trait |
| `admin.enabled` | `true` | Enable admin panel routes |
| `admin.prefix` | `admin/payments` | Admin route prefix |
| `admin.middleware` | `['web', 'auth', 'role:super-admin,admin']` | Admin middleware |
| `invoice.*` | — | Company details, prefix, storage |

## Conventions

- `declare(strict_types=1)` and `final class` on all concrete classes
- All amounts stored in **cents** (smallest currency unit)
- Form Request classes for validation (never inline)
- `Builder` return types on scope methods
- Config-driven admin route prefix and middleware
- `AdminNavigation` registry for sidebar items
- Factory classes for all models
- DTOs for data transfer between layers
- Events dispatched for significant state changes

## Common Pitfalls

- All amounts are in **cents** — multiply display values by 100, divide stored values by 100 for display
- `Plan` uses UUIDs (`HasUuids` trait), not auto-increment IDs — use `findBySlug()` for lookups
- Webhook handlers must verify signatures before processing — use `SupportsWebhooks::verifyWebhookSignature()`
- The `Billable` trait requires `stripe_id`, `paypal_id`, `payment_customer_id` columns on the users table
- Driver factory methods in `PaymentGatewayManager` follow the naming convention `create{DriverName}Driver()`

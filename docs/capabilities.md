# Driver capabilities

What each driver implements. `tests/Unit/Drivers/CapabilityMatrixTest.php` checks this table against the interfaces each driver class implements, so a row can't claim a feature the driver doesn't have.

| Capability | Stripe | PayPal | Crypto | Bank transfer | Cash on delivery |
|---|---|---|---|---|---|
| Hosted checkout | yes | no | no | no | no |
| Customers | yes | yes | no | no | no |
| Refunds | yes | yes | no | no | no |
| Subscriptions | yes | yes | no | no | no |
| Webhooks | yes | yes | yes | no | no |

Every driver supports `createPaymentIntent()`, `charge()`, `getPayment()` and `cancel()`. Check for an optional capability before you call it:

```php
$gateway = Payment::driver('paypal');

if ($gateway instanceof \LaravelPlus\PaymentGateway\Contracts\SupportsRefunds) {
    $gateway->partialRefund($transactionId, 500);
}
```

## Behaviour covered by tests

All tests use fake clients and synthetic IDs. None uses real credentials or makes live API calls.

| Area | Covered | Where |
|---|---|---|
| Webhook security | signature failure, missing secret, replay, malformed / oversized body, raw-byte PayPal verification, id-less crypto events | `WebhookControllerTest`, `WebhookEnvelopeTest`, `StripeWebhookSignatureTest`, `PayPalWebhookSignatureTest` |
| Payments (Stripe) | intent + charge in any order, redelivery, failure then success, late failure, unknown buyer, retry after a listener exception | `StripePaymentIdentityTest` |
| Payments (PayPal) | capture by order id, order + capture pair, denied, unknown capture | `PayPalWebhookListenerTest` |
| Subscriptions | trials and lifecycle events, out-of-order delivery, cancel staying final, plan resolution, multi-item / seats / proration, plan change refusing to guess the item | `TrialLifecycleTest`, `StripeWebhookListenerTest`, `PayPalWebhookListenerTest`, `StripeSubscriptionItemsTest` |
| One-time checkout | payment mode, credit packs, allowed Price catalog, subscription-only options refused | `StripeSubscriptionItemsTest` |
| Invoices | finalized / paid / failed / void / uncollectible in any order, duplicates, concurrent sync lock, one PDF | `StripeWebhookListenerTest` |
| Refunds | pending → succeeded / failed, repeats, partial refunds reaching the total, over-refund, PayPal partial refund | `RefundLifecycleTest`, `AdminRefundTest`, `PayPalWebhookListenerTest` |
| Currency | zero-, two- and three-decimal minor units, rounding | `MoneyTest`, `AbstractPaymentGatewayTest` |
| Schema | every factory and state inserts against the shipped migrations | `FactoriesTest` |

The CI matrix (`.github/workflows/tests.yml`) runs on SQLite. PostgreSQL isn't in CI yet. The migrations use only portable column types, but run the suite against PostgreSQL before a release that adds migrations (see [release checklist](release-checklist.md)).

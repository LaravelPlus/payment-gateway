<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Listeners;

use Stripe\StripeClient;
use LaravelPlus\PaymentGateway\Services\PaymentNotificationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use LaravelPlus\PaymentGateway\Enums\InvoiceStatus;
use LaravelPlus\PaymentGateway\Enums\PaymentStatus;
use LaravelPlus\PaymentGateway\Enums\SubscriptionStatus;
use LaravelPlus\PaymentGateway\Events\PaymentFailed;
use LaravelPlus\PaymentGateway\Events\PaymentSucceeded;
use LaravelPlus\PaymentGateway\Events\PaymentWebhookReceived;
use LaravelPlus\PaymentGateway\Events\RefundProcessed;
use LaravelPlus\PaymentGateway\Events\SubscriptionCanceled;
use LaravelPlus\PaymentGateway\Events\SubscriptionCreated;
use LaravelPlus\PaymentGateway\Models\Invoice;
use LaravelPlus\PaymentGateway\Models\Refund;
use LaravelPlus\PaymentGateway\Models\Plan;
use LaravelPlus\PaymentGateway\Models\Subscription;
use LaravelPlus\PaymentGateway\Models\Transaction;
use LaravelPlus\PaymentGateway\Services\InvoicePdfGenerator;
use LaravelPlus\PaymentGateway\Services\TrialLifecycle;

/**
 * Handle Stripe webhook events and update database accordingly.
 */
final class HandleStripeWebhook
{
    public function __construct(
        private readonly InvoicePdfGenerator $pdfGenerator,
        private readonly PaymentNotificationService $notifications,
        private readonly TrialLifecycle $trials,
    ) {}

    /**
     * Handle the event.
     */
    public function handle(PaymentWebhookReceived $event): void
    {
        if ($event->payload->driver !== 'stripe') {
            return;
        }

        $type = $event->payload->type;
        $data = $event->payload->data;

        Log::info('Processing Stripe webhook', ['type' => $type]);

        match ($type) {
            // Payment Intent events
            'payment_intent.succeeded' => $this->handlePaymentSucceeded($data),
            'payment_intent.payment_failed' => $this->handlePaymentFailed($data),

            // Charge events
            'charge.succeeded' => $this->handleChargeSucceeded($data),
            'charge.failed' => $this->handleChargeFailed($data),
            'charge.refunded' => $this->handleChargeRefunded($data),
            'charge.refund.updated', 'refund.created', 'refund.updated', 'refund.failed' => $this->handleRefundUpdated($data),

            // Subscription events
            'customer.subscription.created' => $this->handleSubscriptionCreated($data),
            'customer.subscription.updated' => $this->handleSubscriptionUpdated($data),
            'customer.subscription.deleted' => $this->handleSubscriptionDeleted($data),
            'customer.subscription.trial_will_end' => $this->handleTrialWillEnd($data),

            // Invoice events
            'invoice.paid' => $this->syncInvoice($data, InvoiceStatus::Paid),
            'invoice.payment_failed' => $this->handleInvoicePaymentFailed($data),
            'invoice.finalized' => $this->syncInvoice($data, InvoiceStatus::Open),
            'invoice.voided' => $this->syncInvoice($data, InvoiceStatus::Void),
            'invoice.marked_uncollectible' => $this->syncInvoice($data, InvoiceStatus::Uncollectible),

            // Dispute events
            'charge.dispute.created', 'charge.dispute.closed', 'radar.early_fraud_warning.created' => $this->handleDispute($data, $type),

            default => Log::debug('Unhandled Stripe webhook type', ['type' => $type]),
        };
    }

    /**
     * A Stripe payment is one PaymentIntent plus its Charge. Both events land on
     * the one transaction keyed by the PaymentIntent id (what createPaymentIntent()
     * and charge() store); charges without an intent (legacy API) key on their own id.
     */
    private function handlePaymentSucceeded(array $data): void
    {
        $transaction = $this->paymentTransaction($data['id'], $data['latest_charge'] ?? null, $data);

        if ($transaction) {
            $this->markSucceeded($transaction, $data, $data['invoice'] ?? null);
        }
    }

    private function handlePaymentFailed(array $data): void
    {
        $transaction = $this->findPaymentTransaction([$data['id'], $data['latest_charge'] ?? null]);

        if ($transaction) {
            $this->markFailed($transaction, $data['last_payment_error']['message'] ?? 'Payment failed', $data);
        }
    }

    private function handleChargeSucceeded(array $data): void
    {
        $transaction = $this->paymentTransaction($data['payment_intent'] ?? $data['id'], $data['id'], $data);

        if ($transaction) {
            $this->markSucceeded($transaction, $data, $data['invoice'] ?? null);
        }
    }

    private function handleChargeFailed(array $data): void
    {
        $transaction = $this->findPaymentTransaction([$data['payment_intent'] ?? null, $data['id']]);

        if ($transaction) {
            $this->markFailed($transaction, $data['failure_message'] ?? 'Charge failed', $data);
        }
    }

    /**
     * @param  array<int, mixed>  $ids
     */
    private function findPaymentTransaction(array $ids): ?Transaction
    {
        $ids = array_values(array_filter($ids, fn ($id) => is_string($id) && $id !== ''));

        return $ids === [] ? null : Transaction::where('driver', 'stripe')->whereIn('provider_id', $ids)->first();
    }

    /**
     * The payment's transaction, recorded under the PaymentIntent id if the app
     * never started it, but only for a customer we know: a stranger's payment is
     * logged for reconciliation, never fulfilled.
     */
    private function paymentTransaction(string $paymentId, ?string $chargeId, array $object): ?Transaction
    {
        // Lock per payment: the intent and charge events often arrive together, and both may need to create the row.
        return Cache::lock('payment-gateway:stripe-payment:'.$paymentId, 30)
            ->block((int) config('payment-gateway.webhooks.lock_wait', 10), function () use ($paymentId, $chargeId, $object): ?Transaction {
                $transaction = $this->findPaymentTransaction([$paymentId, $chargeId]);
                if ($transaction) {
                    return $transaction;
                }

                $user = $this->findUserByCustomer($object['customer'] ?? null);
                if (!$user) {
                    Log::warning('Stripe payment from an unknown customer was not recorded', ['payment_intent' => $paymentId, 'customer' => $object['customer'] ?? null]);

                    return null;
                }

                return Transaction::create([
                    'user_id' => $user->id,
                    'driver' => 'stripe',
                    'provider_id' => $paymentId,
                    'provider_payment_method_id' => $object['payment_method'] ?? null,
                    'amount' => $object['amount'] ?? 0,
                    'currency' => mb_strtoupper((string) ($object['currency'] ?? '')),
                    'status' => PaymentStatus::Pending->value,
                    'description' => $object['description'] ?? null,
                    // Checkout copies the app's order reference onto the PaymentIntent (gh-31).
                    'metadata' => $object['metadata'] ?? null,
                    'provider_response' => $object,
                ]);
            });
    }

    /**
     * The one place a Stripe payment becomes paid. The conditional update is the
     * guard: only the event that flips it gets the invoice and PaymentSucceeded,
     * and paid or refunded payments are never flipped again.
     */
    private function markSucceeded(Transaction $transaction, array $object, ?string $stripeInvoiceId): void
    {
        $flipped = Transaction::whereKey($transaction->id)
            ->whereNotIn('status', [PaymentStatus::Succeeded->value, PaymentStatus::Refunded->value, PaymentStatus::PartiallyRefunded->value, PaymentStatus::Disputed->value])
            ->update(['status' => PaymentStatus::Succeeded->value, 'failure_message' => null, 'provider_response' => json_encode($object)]);

        if ($flipped === 0) {
            return;
        }

        $transaction->refresh();

        // A Stripe invoice's own events own its local invoice.
        if ($stripeInvoiceId === null && !Invoice::where('transaction_id', $transaction->id)->exists()) {
            $this->pdfGenerator->generate(Invoice::createFromTransaction($transaction));
        }

        event(new PaymentSucceeded($transaction->toDto()));
    }

    /**
     * Failure only lands on a payment that isn't settled: a late failure event
     * can't undo a success, and a repeat doesn't fire PaymentFailed twice.
     */
    private function markFailed(Transaction $transaction, string $message, array $object): void
    {
        $flipped = Transaction::whereKey($transaction->id)
            ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Processing->value, PaymentStatus::RequiresAction->value, PaymentStatus::RequiresCapture->value])
            ->update(['status' => PaymentStatus::Failed->value, 'failure_message' => $message, 'provider_response' => json_encode($object)]);

        if ($flipped === 1) {
            event(new PaymentFailed($transaction->refresh()->toDto(), $message));
        }
    }

    private function handleChargeRefunded(array $data): void
    {
        $charge = $data;

        // A charge from before webhooks were enabled has no transaction yet; it is
        // recorded under the same PaymentIntent id the rest of the payment uses.
        $transaction = $this->findPaymentTransaction([$charge['payment_intent'] ?? null, $charge['id']])
            ?? Transaction::create([
                'user_id' => $this->findUserByCustomer($charge['customer'] ?? null)?->id,
                'driver' => 'stripe',
                'provider_id' => $charge['payment_intent'] ?? $charge['id'],
                'provider_payment_method_id' => $charge['payment_method'] ?? null,
                'amount' => $charge['amount'],
                'currency' => mb_strtoupper($charge['currency']),
                'status' => PaymentStatus::Succeeded->value,
                'description' => $charge['description'] ?? null,
                'provider_response' => $charge,
            ]);

        foreach ($charge['refunds']['data'] ?? $this->fetchRefunds($charge['id']) as $stripeRefund) {
            $this->syncRefund($transaction, $stripeRefund);
        }
    }

    /**
     * A refund's own lifecycle: pending, then succeeded, failed or canceled.
     */
    private function handleRefundUpdated(array $stripeRefund): void
    {
        $transaction = $this->findPaymentTransaction([$stripeRefund['payment_intent'] ?? null, $stripeRefund['charge'] ?? null]);

        if (!$transaction) {
            Log::warning('Stripe refund for an unknown payment', ['refund' => $stripeRefund['id'] ?? null, 'charge' => $stripeRefund['charge'] ?? null]);

            return;
        }

        $this->syncRefund($transaction, $stripeRefund);
    }

    /**
     * Upsert one refund by its Stripe id; the Refund model recomputes the payment's
     * totals from settled refunds. RefundProcessed fires once, when it settles.
     */
    private function syncRefund(Transaction $transaction, array $stripeRefund): void
    {
        Cache::lock('payment-gateway:stripe-refund:'.$stripeRefund['id'], 30)
            ->block((int) config('payment-gateway.webhooks.lock_wait', 10), function () use ($transaction, $stripeRefund): void {
                $refund = Refund::firstOrNew(['provider_id' => $stripeRefund['id'], 'driver' => 'stripe']);
                $wasSettled = $refund->exists && $refund->isSettled();

                $refund->fill([
                    'transaction_id' => $transaction->id,
                    'user_id' => $transaction->user_id,
                    'amount' => $stripeRefund['amount'],
                    'currency' => mb_strtoupper((string) $stripeRefund['currency']),
                    'status' => $stripeRefund['status'],
                    'reason' => $stripeRefund['reason'] ?? $refund->reason,
                    'failure_reason' => $stripeRefund['failure_reason'] ?? null,
                    'provider_response' => $stripeRefund,
                ])->save();

                if (!$wasSettled && $refund->isSettled()) {
                    event(new RefundProcessed($refund->toDto()));
                }
            });
    }

    /**
     * Stripe API 2022-11-15 and later leave refunds out of the charge event.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchRefunds(string $chargeId): array
    {
        $stripe = app(StripeClient::class, ['config' => (string) config('payment-gateway.drivers.stripe.secret')]);

        return array_map(
            static fn ($refund): array => $refund->toArray(),
            $stripe->refunds->all(['charge' => $chargeId, 'limit' => 100])->data,
        );
    }

    private function handleSubscriptionCreated(array $data): void
    {
        $stripeSubscription = $data;

        $subscription = $this->syncSubscription($stripeSubscription);

        if ($subscription->wasRecentlyCreated) {
            event(new SubscriptionCreated($subscription->toDto()));
        }
    }

    private function handleSubscriptionUpdated(array $data): void
    {
        $stripeSubscription = $data;

        $this->syncSubscription($stripeSubscription);
    }

    private function handleSubscriptionDeleted(array $data): void
    {
        $stripeSubscription = $data;

        $subscription = Subscription::where('provider_id', $stripeSubscription['id'])
            ->where('driver', 'stripe')
            ->first();

        if ($subscription) {
            $before = $subscription->status;

            $subscription->update([
                'status' => SubscriptionStatus::Canceled->value,
                'canceled_at' => now(),
                'ended_at' => now(),
            ]);

            event(new SubscriptionCanceled($subscription->toDto()));
            $this->trials->transition($before, $subscription);
        }
    }

    private function handleTrialWillEnd(array $data): void
    {
        $stripeSubscription = $data;

        $subscription = Subscription::where('provider_id', $stripeSubscription['id'])
            ->where('driver', 'stripe')
            ->first();

        if ($subscription) {
            // Stripe sends this 3 days out (at once for shorter trials). It shares the
            // scheduler's reminder ledger, so the customer is reminded once per lead.
            $this->trials->remind($subscription);
        }
    }

    /**
     * Upsert the one local invoice for a Stripe invoice, whatever order its events arrive in.
     */
    private function syncInvoice(array $stripeInvoice, InvoiceStatus $eventStatus): ?Invoice
    {
        $customerId = $stripeInvoice['customer'] ?? null;
        $user = $this->findUserByCustomer($customerId);

        if (!$user) {
            // ponytail: a log line is the reconciliation trail; add a table if unmatched invoices need a UI.
            Log::warning('Stripe invoice for an unknown customer', ['invoice' => $stripeInvoice['id'], 'customer' => $customerId]);

            return null;
        }

        // finalized and paid often land milliseconds apart on different workers: without the lock
        // both miss the row and insert one each. Needs a lock-capable cache shared by all workers
        // (redis, database, memcached). If the lock can't be had in time, the 5xx makes Stripe retry.
        return Cache::lock('payment-gateway:invoice-sync:'.$stripeInvoice['id'], 30)
            ->block((int) config('payment-gateway.webhooks.lock_wait', 10), fn (): Invoice => $this->upsertInvoice($stripeInvoice, $eventStatus, $user));
    }

    /**
     * Runs under the per-invoice lock, PDF included, so side effects happen once.
     */
    private function upsertInvoice(array $stripeInvoice, InvoiceStatus $eventStatus, object $user): Invoice
    {
        $invoice = Invoice::firstOrNew(['provider_id' => $stripeInvoice['id'], 'driver' => 'stripe']);
        $status = InvoiceStatus::tryFrom((string) ($stripeInvoice['status'] ?? '')) ?? $eventStatus;
        $current = $invoice->exists ? $invoice->getStatusEnum() : null;

        $subscriptionId = $stripeInvoice['subscription']
            ?? $stripeInvoice['parent']['subscription_details']['subscription'] // API 2025-03+
            ?? null;

        $invoice->fill([
            'user_id' => $user->id,
            // Only this customer's subscription, never another account's.
            'subscription_id' => ($subscriptionId
                ? Subscription::where('provider_id', $subscriptionId)->where('driver', 'stripe')->where('user_id', $user->id)->value('id')
                : null) ?? $invoice->subscription_id,
        ]);

        // Paid and void are final: a late finalized/payment_failed event carries an older snapshot,
        // so it may link the subscription and finish the PDF but must not reopen or re-price the invoice.
        if (in_array($current, [InvoiceStatus::Paid, InvoiceStatus::Void], true)) {
            $invoice->save();
            $this->generatePaidPdf($invoice, $current);

            return $invoice;
        }

        $invoice->fill([
            'status' => $status->value,
            'subtotal' => $stripeInvoice['subtotal'] ?? 0,
            'tax' => $stripeInvoice['tax'] ?? 0,
            'total' => $stripeInvoice['total'] ?? 0,
            'amount_paid' => $stripeInvoice['amount_paid'] ?? 0,
            'amount_due' => $stripeInvoice['amount_due'] ?? 0,
            'currency' => mb_strtoupper((string) ($stripeInvoice['currency'] ?? '')),
            'invoice_date' => isset($stripeInvoice['created']) ? \Carbon\Carbon::createFromTimestamp($stripeInvoice['created']) : ($invoice->invoice_date ?? now()),
            'due_date' => isset($stripeInvoice['due_date']) ? \Carbon\Carbon::createFromTimestamp($stripeInvoice['due_date']) : null,
            'paid_at' => $status === InvoiceStatus::Paid ? ($invoice->paid_at ?? now()) : null,
            'voided_at' => $status === InvoiceStatus::Void ? ($invoice->voided_at ?? now()) : null,
            'line_items' => $this->mapStripeLineItems($stripeInvoice['lines']['data'] ?? []),
            'provider_response' => $stripeInvoice,
        ])->save();

        $this->generatePaidPdf($invoice, $status);

        return $invoice;
    }

    /**
     * An open or failed invoice is not a receipt; the PDF is made once, when it is paid.
     */
    private function generatePaidPdf(Invoice $invoice, InvoiceStatus $status): void
    {
        if ($status === InvoiceStatus::Paid && !$invoice->hasPdf()) {
            $this->pdfGenerator->generate($invoice);
        }
    }

    private function handleInvoicePaymentFailed(array $data): void
    {
        $stripeInvoice = $data;

        $this->syncInvoice($stripeInvoice, InvoiceStatus::Open);

        $user = $this->findUserByCustomer($stripeInvoice['customer'] ?? null);

        // Stripe retries a failed invoice; the customer hears about it once.
        if ($user?->email && Cache::add('payment-gateway:invoice-failed:'.$stripeInvoice['id'], true, now()->addDays(60))) {
            $this->notifications->sendInvoicePaymentFailed(
                $user,
                (int) ($stripeInvoice['amount_due'] ?? 0),
                (string) ($stripeInvoice['currency'] ?? ''),
                $stripeInvoice['hosted_invoice_url'] ?? null,
            );
        }
    }

    private function handleDispute(array $dispute, string $type): void
    {
        Log::critical('Stripe dispute', [
            'type' => $type,
            'dispute_id' => $dispute['id'] ?? null,
            'charge_id' => $dispute['charge'] ?? null,
            'amount' => $dispute['amount'] ?? null,
            'currency' => $dispute['currency'] ?? null,
            'reason' => $dispute['reason'] ?? $dispute['fraud_type'] ?? null,
            'status' => $dispute['status'] ?? null,
        ]);

        $this->notifications->sendDisputeAlert($type, $dispute);
    }

    /**
     * Sync subscription from Stripe data.
     */
    private function syncSubscription(array $stripeSubscription): Subscription
    {
        $items = $stripeSubscription['items']['data'] ?? [];
        if ($items === []) {
            throw new \UnexpectedValueException("Stripe subscription {$stripeSubscription['id']} has no items, so its plan can't be resolved.");
        }

        // With a base + seat subscription the base item is the one a local Plan
        // owns; without any Plan rows it is the first item, as before.
        $plans = Plan::whereIn('stripe_price_id', array_filter(array_map(fn ($item) => $item['price']['id'] ?? null, $items)))
            ->get()
            ->keyBy('stripe_price_id');
        $base = collect($items)->first(fn ($item) => $plans->has($item['price']['id'] ?? '')) ?? $items[0];
        $price = $base['price'] ?? [];
        $plan = $plans->get($price['id'] ?? '');

        $user = $this->findUserByCustomer($stripeSubscription['customer']);

        $subscription = Subscription::firstOrNew(['provider_id' => $stripeSubscription['id'], 'driver' => 'stripe']);
        $before = $subscription->status;

        // Canceled is final at Stripe, so a canceled row can only be overwritten by an
        // older event that arrived late. Ignore it rather than give the access back.
        if ($subscription->exists && $before === SubscriptionStatus::Canceled->value) {
            return $subscription;
        }

        // Required on create; never overwritten, because the app may have set it.
        if (! $subscription->exists) {
            $subscription->plan_id = $stripeSubscription['metadata']['plan']
                ?? $plan?->slug
                ?? $price['id']
                ?? throw new \UnexpectedValueException("Stripe subscription {$stripeSubscription['id']} has no price on its base item.");
        }

        $subscription->fill(
            [
                'user_id' => $user?->id,
                // Derived from the Price, so it follows plan changes; plan_id above is the app's.
                'payment_plan_id' => $plan?->id ?? $subscription->payment_plan_id,
                'provider_plan_id' => $price['id'] ?? null,
                'status' => $this->mapStripeStatus($stripeSubscription['status']),
                'amount' => $price['unit_amount'] ?? 0,
                'currency' => mb_strtoupper($stripeSubscription['currency']),
                'interval' => $price['recurring']['interval'] ?? 'month',
                'interval_count' => $price['recurring']['interval_count'] ?? 1,
                // The top-level quantity is empty once items carry their own.
                'quantity' => $base['quantity'] ?? $stripeSubscription['quantity'] ?? 1,
                'current_period_start' => $this->periodTimestamp($stripeSubscription, 'current_period_start'),
                'current_period_end' => $this->periodTimestamp($stripeSubscription, 'current_period_end'),
                'trial_start' => isset($stripeSubscription['trial_start'])
                    ? \Carbon\Carbon::createFromTimestamp($stripeSubscription['trial_start'])
                    : null,
                'trial_end' => isset($stripeSubscription['trial_end'])
                    ? \Carbon\Carbon::createFromTimestamp($stripeSubscription['trial_end'])
                    : null,
                'canceled_at' => isset($stripeSubscription['canceled_at'])
                    ? \Carbon\Carbon::createFromTimestamp($stripeSubscription['canceled_at'])
                    : null,
                'cancel_at_period_end' => $stripeSubscription['cancel_at_period_end'] ?? false,
                'provider_response' => $stripeSubscription,
            ]
        )->save();

        // Invoice events can beat customer.subscription.created; link the invoices that arrived first.
        Invoice::where('driver', 'stripe')
            ->where('user_id', $subscription->user_id)
            ->whereNull('subscription_id')
            ->where(fn ($q) => $q->where('provider_response->subscription', $stripeSubscription['id'])
                ->orWhere('provider_response->parent->subscription_details->subscription', $stripeSubscription['id']))
            ->update(['subscription_id' => $subscription->id]);

        if ($before !== $subscription->status) {
            $this->trials->transition($before, $subscription);
        }

        return $subscription;
    }

    /**
     * Stripe API 2025-03-31 and later keep the period on the subscription item.
     */
    private function periodTimestamp(array $stripeSubscription, string $field): ?\Carbon\Carbon
    {
        $timestamp = $stripeSubscription[$field] ?? $stripeSubscription['items']['data'][0][$field] ?? null;

        return $timestamp !== null ? \Carbon\Carbon::createFromTimestamp($timestamp) : null;
    }

    /**
     * Map Stripe subscription status to our status.
     */
    private function mapStripeStatus(string $status): string
    {
        return match ($status) {
            'active' => SubscriptionStatus::Active->value,
            'trialing' => SubscriptionStatus::Trialing->value,
            'past_due' => SubscriptionStatus::PastDue->value,
            'canceled' => SubscriptionStatus::Canceled->value,
            'unpaid' => SubscriptionStatus::Unpaid->value,
            'incomplete' => SubscriptionStatus::Incomplete->value,
            'incomplete_expired' => SubscriptionStatus::Expired->value,
            'paused' => SubscriptionStatus::Paused->value,
            default => SubscriptionStatus::Incomplete->value,
        };
    }

    /**
     * Find user by Stripe customer ID.
     */
    private function findUserByCustomer(?string $customerId): ?object
    {
        if (!$customerId) {
            return null;
        }

        $paymentCustomer = \LaravelPlus\PaymentGateway\Models\PaymentCustomer::where('stripe_id', $customerId)
            ->first();

        // Billable::createAsPaymentCustomer() stores the ID on users.stripe_id, not in payment_customers.
        return $paymentCustomer?->user
            ?? config('auth.providers.users.model')::where('stripe_id', $customerId)->first();
    }

    /**
     * Map Stripe line items to our format.
     *
     * @return array<array<string, mixed>>
     */
    private function mapStripeLineItems(array $lines): array
    {
        return collect($lines)->map(fn ($line) => [
            'description' => $line['description'] ?? 'Subscription',
            'quantity' => $line['quantity'] ?? 1,
            'unit_price' => $line['price']['unit_amount'] ?? $line['amount'],
            'amount' => $line['amount'],
        ])->toArray();
    }
}

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

    private function handlePaymentSucceeded(array $data): void
    {
        $paymentIntent = $data;

        $transaction = Transaction::where('provider_id', $paymentIntent['id'])
            ->where('driver', 'stripe')
            ->first();

        if ($transaction) {
            $transaction->update([
                'status' => PaymentStatus::Succeeded->value,
                'provider_response' => $paymentIntent,
            ]);

            // A Stripe invoice's own events own its local invoice; a repeated event must not add a second one.
            if (empty($paymentIntent['invoice']) && !Invoice::where('transaction_id', $transaction->id)->exists()) {
                $this->pdfGenerator->generate(Invoice::createFromTransaction($transaction));
            }

            event(new PaymentSucceeded($transaction->toDto()));
        }
    }

    private function handlePaymentFailed(array $data): void
    {
        $paymentIntent = $data;

        $transaction = Transaction::where('provider_id', $paymentIntent['id'])
            ->where('driver', 'stripe')
            ->first();

        if ($transaction) {
            $failureMessage = $paymentIntent['last_payment_error']['message'] ?? 'Payment failed';

            $transaction->update([
                'status' => PaymentStatus::Failed->value,
                'failure_message' => $failureMessage,
                'provider_response' => $paymentIntent,
            ]);

            event(new PaymentFailed($transaction->toDto(), $failureMessage));
        }
    }

    private function handleChargeSucceeded(array $data): void
    {
        $charge = $data;

        // Find or create transaction
        $transaction = Transaction::firstOrCreate(
            ['provider_id' => $charge['id'], 'driver' => 'stripe'],
            [
                'user_id' => $this->findUserByCustomer($charge['customer'] ?? null)?->id,
                'amount' => $charge['amount'],
                'currency' => mb_strtoupper($charge['currency']),
                'status' => PaymentStatus::Succeeded->value,
                'payment_method' => $charge['payment_method'] ?? null,
                'description' => $charge['description'] ?? null,
                'provider_response' => $charge,
            ]
        );

        if ($transaction->wasRecentlyCreated) {
            event(new PaymentSucceeded($transaction->toDto()));
        }
    }

    private function handleChargeFailed(array $data): void
    {
        $charge = $data;

        $transaction = Transaction::where('provider_id', $charge['id'])
            ->where('driver', 'stripe')
            ->first();

        if ($transaction) {
            $transaction->update([
                'status' => PaymentStatus::Failed->value,
                'failure_message' => $charge['failure_message'] ?? 'Charge failed',
            ]);
        }
    }

    private function handleChargeRefunded(array $data): void
    {
        $charge = $data;

        // A charge from before webhooks were enabled has no transaction yet.
        $transaction = Transaction::firstOrCreate(
            ['provider_id' => $charge['id'], 'driver' => 'stripe'],
            [
                'user_id' => $this->findUserByCustomer($charge['customer'] ?? null)?->id,
                'amount' => $charge['amount'],
                'currency' => mb_strtoupper($charge['currency']),
                'status' => PaymentStatus::Succeeded->value,
                'payment_method' => $charge['payment_method'] ?? null,
                'description' => $charge['description'] ?? null,
                'provider_response' => $charge,
            ]
        );

        // Create refund records for each refund
        foreach ($charge['refunds']['data'] ?? $this->fetchRefunds($charge['id']) as $stripeRefund) {
            $refund = Refund::firstOrCreate(
                ['provider_id' => $stripeRefund['id'], 'driver' => 'stripe'],
                [
                    'transaction_id' => $transaction->id,
                    'user_id' => $transaction->user_id,
                    'amount' => $stripeRefund['amount'],
                    'currency' => mb_strtoupper($stripeRefund['currency']),
                    'status' => $stripeRefund['status'],
                    'reason' => $stripeRefund['reason'],
                    'provider_response' => $stripeRefund,
                ]
            );

            if ($refund->wasRecentlyCreated) {
                event(new RefundProcessed($refund->toDto()));
            }
        }

        // Update transaction status if fully refunded
        if ($charge['refunded']) {
            $transaction->update(['status' => PaymentStatus::Refunded->value]);
        }
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

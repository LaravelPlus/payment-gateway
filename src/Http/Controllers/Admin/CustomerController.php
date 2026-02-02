<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Nejcc\PaymentGateway\Models\PaymentCustomer;

final class CustomerController extends Controller
{
    public function index(Request $request): Response
    {
        $query = PaymentCustomer::with('user')
            ->withCount(['transactions', 'subscriptions'])
            ->latest();

        // Filters
        if ($request->filled('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search): void {
                $q->where('email', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('stripe_id', 'like', "%{$search}%")
                    ->orWhere('paypal_id', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search): void {
                        $uq->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->boolean('has_subscriptions')) {
            $query->has('subscriptions');
        }

        $customers = $query->paginate(20)->withQueryString();

        return Inertia::render('admin/payments/customers/Index', [
            'customers' => $customers->through(fn (PaymentCustomer $c) => [
                'id' => $c->id,
                'email' => $c->email,
                'name' => $c->name,
                'phone' => $c->phone,
                'stripe_id' => $c->stripe_id,
                'paypal_id' => $c->paypal_id,
                'is_business' => $c->is_business,
                'transactions_count' => $c->transactions_count,
                'subscriptions_count' => $c->subscriptions_count,
                'user' => $c->user ? [
                    'id' => $c->user->id,
                    'name' => $c->user->name,
                    'email' => $c->user->email,
                ] : null,
                'created_at' => $c->created_at->toISOString(),
            ]),
            'filters' => $request->only(['search', 'has_subscriptions']),
        ]);
    }

    public function show(PaymentCustomer $customer): Response
    {
        $customer->load(['user', 'transactions', 'subscriptions.plan', 'paymentMethods']);

        // Calculate totals
        $totalSpent = $customer->transactions()
            ->where('status', 'succeeded')
            ->sum('amount');

        return Inertia::render('admin/payments/customers/Show', [
            'customer' => [
                'id' => $customer->id,
                'email' => $customer->email,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'stripe_id' => $customer->stripe_id,
                'paypal_id' => $customer->paypal_id,
                'crypto_id' => $customer->crypto_id,
                'preferred_locale' => $customer->preferred_locale,
                'tax_id' => $customer->tax_id,
                'vat_number' => $customer->vat_number,
                'is_business' => $customer->is_business,
                'is_primary' => $customer->is_primary,
                'company' => $customer->company,
                'billing_address' => $customer->billing_address,
                'shipping_address' => $customer->shipping_address,
                'invoice_address' => $customer->invoice_address,
                'metadata' => $customer->metadata,
                'total_spent' => $totalSpent,
                'formatted_total_spent' => $this->formatMoney($totalSpent, config('payment-gateway.currency', 'USD')),
                'user' => $customer->user ? [
                    'id' => $customer->user->id,
                    'name' => $customer->user->name,
                    'email' => $customer->user->email,
                ] : null,
                'transactions' => $customer->transactions()
                    ->latest()
                    ->take(10)
                    ->get()
                    ->map(fn ($t) => [
                        'id' => $t->id,
                        'uuid' => $t->uuid,
                        'amount' => $t->amount,
                        'formatted_amount' => $t->getFormattedAmount(),
                        'status' => $t->status,
                        'driver' => $t->driver,
                        'created_at' => $t->created_at->toISOString(),
                    ]),
                'subscriptions' => $customer->subscriptions->map(fn ($s) => [
                    'id' => $s->id,
                    'uuid' => $s->uuid,
                    'status' => $s->status,
                    'billing_description' => $s->getBillingDescription(),
                    'plan' => $s->plan ? [
                        'id' => $s->plan->id,
                        'name' => $s->plan->name,
                    ] : null,
                    'current_period_end' => $s->current_period_end?->toISOString(),
                    'created_at' => $s->created_at->toISOString(),
                ]),
                'payment_methods' => $customer->paymentMethods->map(fn ($pm) => [
                    'id' => $pm->id,
                    'type' => $pm->type,
                    'display_name' => $pm->getDisplayName(),
                    'is_default' => $pm->is_default,
                    'is_expired' => $pm->isExpired(),
                    'expiry_string' => $pm->getExpiryString(),
                    'driver' => $pm->driver,
                ]),
                'created_at' => $customer->created_at->toISOString(),
                'updated_at' => $customer->updated_at->toISOString(),
            ],
        ]);
    }

    private function formatMoney(int $cents, string $currency): string
    {
        $formatter = new \NumberFormatter('en', \NumberFormatter::CURRENCY);

        return $formatter->formatCurrency($cents / 100, $currency);
    }
}

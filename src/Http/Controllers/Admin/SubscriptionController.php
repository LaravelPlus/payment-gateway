<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Nejcc\PaymentGateway\Enums\SubscriptionStatus;
use Nejcc\PaymentGateway\Facades\Payment;
use Nejcc\PaymentGateway\Http\Requests\Admin\CancelSubscriptionRequest;
use Nejcc\PaymentGateway\Models\Subscription;

final class SubscriptionController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Subscription::with(['user', 'plan'])
            ->latest();

        // Filters
        if ($request->filled('status')) {
            $query->where('status', $request->get('status'));
        }

        if ($request->filled('driver')) {
            $query->where('driver', $request->get('driver'));
        }

        if ($request->filled('plan')) {
            $query->where('payment_plan_id', $request->get('plan'));
        }

        if ($request->filled('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search): void {
                $q->where('provider_id', 'like', "%{$search}%")
                    ->orWhere('uuid', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search): void {
                        $uq->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        $subscriptions = $query->paginate(20)->withQueryString();

        return Inertia::render('admin/payments/subscriptions/Index', [
            'subscriptions' => $subscriptions->through(fn (Subscription $s) => [
                'id' => $s->id,
                'uuid' => $s->uuid,
                'provider_id' => $s->provider_id,
                'amount' => $s->amount,
                'formatted_amount' => $s->getFormattedAmount(),
                'currency' => $s->currency,
                'status' => $s->status,
                'driver' => $s->driver,
                'interval' => $s->interval,
                'interval_count' => $s->interval_count,
                'billing_description' => $s->getBillingDescription(),
                'plan' => $s->plan ? [
                    'id' => $s->plan->id,
                    'name' => $s->plan->name,
                    'slug' => $s->plan->slug,
                ] : null,
                'user' => $s->user ? [
                    'id' => $s->user->id,
                    'name' => $s->user->name,
                    'email' => $s->user->email,
                ] : null,
                'current_period_end' => $s->current_period_end?->toISOString(),
                'trial_end' => $s->trial_end?->toISOString(),
                'on_trial' => $s->onTrial(),
                'on_grace_period' => $s->onGracePeriod(),
                'created_at' => $s->created_at->toISOString(),
            ]),
            'filters' => $request->only(['status', 'driver', 'plan', 'search']),
            'statuses' => SubscriptionStatus::cases(),
            'drivers' => array_keys(config('payment-gateway.drivers', [])),
        ]);
    }

    public function show(Subscription $subscription): Response
    {
        $subscription->load(['user', 'plan', 'paymentCustomer']);

        return Inertia::render('admin/payments/subscriptions/Show', [
            'subscription' => [
                'id' => $subscription->id,
                'uuid' => $subscription->uuid,
                'provider_id' => $subscription->provider_id,
                'provider_plan_id' => $subscription->provider_plan_id,
                'amount' => $subscription->amount,
                'formatted_amount' => $subscription->getFormattedAmount(),
                'currency' => $subscription->currency,
                'status' => $subscription->status,
                'driver' => $subscription->driver,
                'interval' => $subscription->interval,
                'interval_count' => $subscription->interval_count,
                'quantity' => $subscription->quantity,
                'billing_description' => $subscription->getBillingDescription(),
                'current_period_start' => $subscription->current_period_start?->toISOString(),
                'current_period_end' => $subscription->current_period_end?->toISOString(),
                'trial_start' => $subscription->trial_start?->toISOString(),
                'trial_end' => $subscription->trial_end?->toISOString(),
                'canceled_at' => $subscription->canceled_at?->toISOString(),
                'ended_at' => $subscription->ended_at?->toISOString(),
                'cancel_at_period_end' => $subscription->cancel_at_period_end,
                'paused_at' => $subscription->paused_at?->toISOString(),
                'resume_at' => $subscription->resume_at?->toISOString(),
                'on_trial' => $subscription->onTrial(),
                'on_grace_period' => $subscription->onGracePeriod(),
                'is_active' => $subscription->isActive(),
                'is_canceled' => $subscription->isCanceled(),
                'is_paused' => $subscription->isPaused(),
                'days_remaining' => $subscription->daysRemaining(),
                'metadata' => $subscription->metadata,
                'provider_response' => $subscription->provider_response,
                'plan' => $subscription->plan ? [
                    'id' => $subscription->plan->id,
                    'name' => $subscription->plan->name,
                    'slug' => $subscription->plan->slug,
                    'amount' => $subscription->plan->amount,
                    'formatted_price' => $subscription->plan->formatted_price,
                ] : null,
                'user' => $subscription->user ? [
                    'id' => $subscription->user->id,
                    'name' => $subscription->user->name,
                    'email' => $subscription->user->email,
                ] : null,
                'customer' => $subscription->paymentCustomer ? [
                    'id' => $subscription->paymentCustomer->id,
                    'email' => $subscription->paymentCustomer->email,
                    'name' => $subscription->paymentCustomer->name,
                ] : null,
                'can_cancel' => $subscription->isActive() && ! $subscription->isCanceled(),
                'can_resume' => $subscription->onGracePeriod(),
                'created_at' => $subscription->created_at->toISOString(),
                'updated_at' => $subscription->updated_at->toISOString(),
            ],
        ]);
    }

    public function cancel(CancelSubscriptionRequest $request, Subscription $subscription): RedirectResponse
    {

        if (! $subscription->isActive()) {
            return back()->with('error', 'Cannot cancel a subscription that is not active.');
        }

        try {
            $immediately = $request->boolean('immediately', false);

            if ($subscription->driver && $subscription->provider_id) {
                Payment::driver($subscription->driver)->cancelSubscription(
                    $subscription->provider_id,
                    $immediately
                );
            }

            if ($immediately) {
                $subscription->update([
                    'status' => SubscriptionStatus::Canceled->value,
                    'canceled_at' => now(),
                    'ended_at' => now(),
                ]);
            } else {
                $subscription->update([
                    'cancel_at_period_end' => true,
                    'canceled_at' => now(),
                ]);
            }

            return back()->with('success', 'Subscription canceled successfully.');
        } catch (\Exception $e) {
            return back()->with('error', 'Failed to cancel subscription: '.$e->getMessage());
        }
    }

    public function resume(Subscription $subscription): RedirectResponse
    {
        if (! $subscription->onGracePeriod()) {
            return back()->with('error', 'Cannot resume a subscription that is not in grace period.');
        }

        try {
            if ($subscription->driver && $subscription->provider_id) {
                Payment::driver($subscription->driver)->resumeSubscription($subscription->provider_id);
            }

            $subscription->update([
                'cancel_at_period_end' => false,
                'canceled_at' => null,
            ]);

            return back()->with('success', 'Subscription resumed successfully.');
        } catch (\Exception $e) {
            return back()->with('error', 'Failed to resume subscription: '.$e->getMessage());
        }
    }
}

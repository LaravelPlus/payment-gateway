<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Nejcc\PaymentGateway\Facades\Payment;
use Nejcc\PaymentGateway\Http\Requests\Admin\StorePlanRequest;
use Nejcc\PaymentGateway\Http\Requests\Admin\SyncPlanRequest;
use Nejcc\PaymentGateway\Http\Requests\Admin\UpdatePlanRequest;
use Nejcc\PaymentGateway\Models\Plan;

final class PlanController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Plan::withCount('subscriptions')
            ->ordered();

        // Filters
        if ($request->filled('interval')) {
            $query->where('interval', $request->get('interval'));
        }

        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        if ($request->filled('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $plans = $query->paginate(20)->withQueryString();

        return Inertia::render('admin/payments/plans/Index', [
            'plans' => $plans->through(fn (Plan $p) => [
                'id' => $p->id,
                'uuid' => $p->uuid,
                'name' => $p->name,
                'slug' => $p->slug,
                'description' => $p->description,
                'amount' => $p->amount,
                'formatted_price' => $p->formatted_price,
                'billing_description' => $p->billing_description,
                'currency' => $p->currency,
                'interval' => $p->interval,
                'interval_count' => $p->interval_count,
                'trial_days' => $p->trial_days,
                'is_active' => $p->is_active,
                'is_public' => $p->is_public,
                'is_featured' => $p->is_featured,
                'is_free' => $p->isFree(),
                'sort_order' => $p->sort_order,
                'subscriptions_count' => $p->subscriptions_count,
                'stripe_price_id' => $p->stripe_price_id,
                'paypal_plan_id' => $p->paypal_plan_id,
                'created_at' => $p->created_at->toISOString(),
            ]),
            'filters' => $request->only(['interval', 'active', 'search']),
            'intervals' => ['day', 'week', 'month', 'year'],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/payments/plans/Create', [
            'intervals' => ['day', 'week', 'month', 'year'],
            'trialOptions' => config('payment-gateway.subscriptions.trial_options', []),
            'defaultCurrency' => config('payment-gateway.currency', 'USD'),
        ]);
    }

    public function store(StorePlanRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $plan = Plan::create($validated);

        return redirect()
            ->route('admin.payments.plans.edit', $plan)
            ->with('success', 'Plan created successfully.');
    }

    public function edit(Plan $plan): Response
    {
        return Inertia::render('admin/payments/plans/Edit', [
            'plan' => [
                'id' => $plan->id,
                'uuid' => $plan->uuid,
                'name' => $plan->name,
                'slug' => $plan->slug,
                'description' => $plan->description,
                'amount' => $plan->amount,
                'formatted_price' => $plan->formatted_price,
                'currency' => $plan->currency,
                'interval' => $plan->interval,
                'interval_count' => $plan->interval_count,
                'trial_days' => $plan->trial_days,
                'features' => $plan->features ?? [],
                'limits' => $plan->limits ?? [],
                'is_active' => $plan->is_active,
                'is_public' => $plan->is_public,
                'is_featured' => $plan->is_featured,
                'sort_order' => $plan->sort_order,
                'stripe_price_id' => $plan->stripe_price_id,
                'stripe_product_id' => $plan->stripe_product_id,
                'paypal_plan_id' => $plan->paypal_plan_id,
                'metadata' => $plan->metadata,
            ],
            'intervals' => ['day', 'week', 'month', 'year'],
            'trialOptions' => config('payment-gateway.subscriptions.trial_options', []),
        ]);
    }

    public function update(UpdatePlanRequest $request, Plan $plan): RedirectResponse
    {
        $validated = $request->validated();

        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $plan->update($validated);

        return back()->with('success', 'Plan updated successfully.');
    }

    public function destroy(Plan $plan): RedirectResponse
    {
        // Check if plan has active subscriptions
        $activeSubscriptions = $plan->subscriptions()
            ->whereIn('status', ['active', 'trialing'])
            ->count();

        if ($activeSubscriptions > 0) {
            return back()->with('error', "Cannot delete plan with {$activeSubscriptions} active subscription(s).");
        }

        // Archive instead of delete if there are any subscriptions
        if ($plan->subscriptions()->count() > 0) {
            $plan->update([
                'is_active' => false,
                'is_public' => false,
                'is_archived' => true,
            ]);

            return redirect()
                ->route('admin.payments.plans.index')
                ->with('success', 'Plan archived successfully (has historical subscriptions).');
        }

        $plan->forceDelete();

        return redirect()
            ->route('admin.payments.plans.index')
            ->with('success', 'Plan deleted successfully.');
    }

    public function sync(SyncPlanRequest $request, Plan $plan): RedirectResponse
    {
        $driver = $request->validated('driver');

        try {
            $gateway = Payment::driver($driver);

            // Create or update product/plan in provider
            $providerPlan = $gateway->createPlan($plan->toDto());

            // Update local plan with provider IDs
            if ($driver === 'stripe') {
                $plan->update([
                    'stripe_product_id' => $providerPlan->productId,
                    'stripe_price_id' => $providerPlan->id,
                ]);
            } elseif ($driver === 'paypal') {
                $plan->update([
                    'paypal_plan_id' => $providerPlan->id,
                ]);
            }

            return back()->with('success', "Plan synced to {$driver} successfully.");
        } catch (\Exception $e) {
            return back()->with('error', "Failed to sync plan: {$e->getMessage()}");
        }
    }
}

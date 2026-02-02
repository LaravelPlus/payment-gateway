<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Http\Controllers\Admin;

use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Nejcc\PaymentGateway\Enums\PaymentStatus;
use Nejcc\PaymentGateway\Enums\SubscriptionStatus;
use Nejcc\PaymentGateway\Models\PaymentCustomer;
use Nejcc\PaymentGateway\Models\Subscription;
use Nejcc\PaymentGateway\Models\Transaction;
use NumberFormatter;

final class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $period = $request->get('period', '30');
        $startDate = now()->subDays((int) $period);

        return Inertia::render('admin/payments/Dashboard', [
            'stats' => $this->getStats($startDate),
            'recentTransactions' => $this->getRecentTransactions(),
            'revenueChart' => $this->getRevenueChart($startDate),
            'period' => $period,
        ]);
    }

    public function stats(Request $request): JsonResponse
    {
        $period = $request->get('period', '30');
        $startDate = now()->subDays((int) $period);

        return response()->json([
            'stats' => $this->getStats($startDate),
            'revenueChart' => $this->getRevenueChart($startDate),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function getStats(DateTimeInterface $startDate): array
    {
        $currency = config('payment-gateway.currency', 'USD');

        // Total revenue (successful transactions)
        $totalRevenue = Transaction::where('status', PaymentStatus::Succeeded->value)
            ->where('created_at', '>=', $startDate)
            ->sum('amount');

        // Previous period for comparison
        $daysDiff = (int) now()->diffInDays($startDate);
        $previousStart = \Carbon\Carbon::parse($startDate)->subDays($daysDiff);
        $previousRevenue = Transaction::where('status', PaymentStatus::Succeeded->value)
            ->whereBetween('created_at', [$previousStart, $startDate])
            ->sum('amount');

        $revenueChange = $previousRevenue > 0
            ? round((($totalRevenue - $previousRevenue) / $previousRevenue) * 100, 1)
            : 0;

        // Transaction counts
        $successfulTransactions = Transaction::where('status', PaymentStatus::Succeeded->value)
            ->where('created_at', '>=', $startDate)
            ->count();

        $failedTransactions = Transaction::where('status', PaymentStatus::Failed->value)
            ->where('created_at', '>=', $startDate)
            ->count();

        // Active subscriptions
        $activeSubscriptions = Subscription::whereIn('status', [
            SubscriptionStatus::Active->value,
            SubscriptionStatus::Trialing->value,
        ])->count();

        $newSubscriptions = Subscription::where('created_at', '>=', $startDate)->count();

        // Customers
        $totalCustomers = PaymentCustomer::count();
        $newCustomers = PaymentCustomer::where('created_at', '>=', $startDate)->count();

        // MRR (Monthly Recurring Revenue)
        $mrr = Subscription::whereIn('status', [
            SubscriptionStatus::Active->value,
            SubscriptionStatus::Trialing->value,
        ])
            ->where('interval', 'month')
            ->sum('amount');

        $yearlyMrr = Subscription::whereIn('status', [
            SubscriptionStatus::Active->value,
            SubscriptionStatus::Trialing->value,
        ])
            ->where('interval', 'year')
            ->sum(DB::raw('amount / 12'));

        $mrr += $yearlyMrr;

        return [
            'revenue' => [
                'total' => $totalRevenue,
                'formatted' => $this->formatMoney($totalRevenue, $currency),
                'change' => $revenueChange,
            ],
            'transactions' => [
                'successful' => $successfulTransactions,
                'failed' => $failedTransactions,
                'total' => $successfulTransactions + $failedTransactions,
            ],
            'subscriptions' => [
                'active' => $activeSubscriptions,
                'new' => $newSubscriptions,
            ],
            'customers' => [
                'total' => $totalCustomers,
                'new' => $newCustomers,
            ],
            'mrr' => [
                'total' => $mrr,
                'formatted' => $this->formatMoney((int) $mrr, $currency),
            ],
            'currency' => $currency,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getRecentTransactions(): array
    {
        return Transaction::with('user')
            ->latest()
            ->take(10)
            ->get()
            ->map(fn (Transaction $t) => [
                'id' => $t->id,
                'uuid' => $t->uuid,
                'amount' => $t->amount,
                'formatted_amount' => $t->getFormattedAmount(),
                'currency' => $t->currency,
                'status' => $t->status,
                'driver' => $t->driver,
                'description' => $t->description,
                'user' => $t->user ? [
                    'id' => $t->user->id,
                    'name' => $t->user->name,
                    'email' => $t->user->email,
                ] : null,
                'created_at' => $t->created_at->toISOString(),
            ])
            ->toArray();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getRevenueChart(DateTimeInterface $startDate): array
    {
        $days = (int) now()->diffInDays($startDate);
        $driver = config('database.default');

        if ($days <= 90) {
            // Group by day
            $groupBy = $driver === 'sqlite'
                ? 'date(created_at)'
                : 'DATE(created_at)';
            $format = 'M d';
        } else {
            // Group by month
            $groupBy = $driver === 'sqlite'
                ? "strftime('%Y-%m', created_at)"
                : "DATE_FORMAT(created_at, '%Y-%m')";
            $format = 'M Y';
        }

        $revenue = Transaction::where('status', PaymentStatus::Succeeded->value)
            ->where('created_at', '>=', $startDate)
            ->selectRaw("{$groupBy} as date, SUM(amount) as total")
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return $revenue->map(fn ($item) => [
            'date' => $item->date,
            'label' => \Carbon\Carbon::parse($item->date)->format($format),
            'total' => $item->total ?? 0,
            'formatted' => $this->formatMoney((int) ($item->total ?? 0), config('payment-gateway.currency', 'USD')),
        ])->toArray();
    }

    private function formatMoney(int $cents, string $currency): string
    {
        $formatter = new NumberFormatter('en', NumberFormatter::CURRENCY);

        return $formatter->formatCurrency($cents / 100, $currency);
    }
}

<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Nejcc\PaymentGateway\Enums\PaymentStatus;
use Nejcc\PaymentGateway\Facades\Payment;
use Nejcc\PaymentGateway\Http\Requests\Admin\RefundTransactionRequest;
use Nejcc\PaymentGateway\Models\Transaction;

final class TransactionController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Transaction::with('user')
            ->latest();

        // Filters
        if ($request->filled('status')) {
            $query->where('status', $request->get('status'));
        }

        if ($request->filled('driver')) {
            $query->where('driver', $request->get('driver'));
        }

        if ($request->filled('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search): void {
                $q->where('provider_id', 'like', "%{$search}%")
                    ->orWhere('uuid', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search): void {
                        $uq->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->get('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->get('to'));
        }

        $transactions = $query->paginate(20)->withQueryString();

        return Inertia::render('admin/payments/transactions/Index', [
            'transactions' => $transactions->through(fn (Transaction $t) => [
                'id' => $t->id,
                'uuid' => $t->uuid,
                'provider_id' => $t->provider_id,
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
                'can_refund' => $t->isSuccessful() && $t->getRefundableAmount() > 0,
            ]),
            'filters' => $request->only(['status', 'driver', 'search', 'from', 'to']),
            'statuses' => PaymentStatus::cases(),
            'drivers' => array_keys(config('payment-gateway.drivers', [])),
        ]);
    }

    public function show(Transaction $transaction): Response
    {
        $transaction->load(['user', 'refunds', 'paymentCustomer']);

        return Inertia::render('admin/payments/transactions/Show', [
            'transaction' => [
                'id' => $transaction->id,
                'uuid' => $transaction->uuid,
                'provider_id' => $transaction->provider_id,
                'amount' => $transaction->amount,
                'amount_refunded' => $transaction->amount_refunded,
                'refundable_amount' => $transaction->getRefundableAmount(),
                'formatted_amount' => $transaction->getFormattedAmount(),
                'currency' => $transaction->currency,
                'status' => $transaction->status,
                'driver' => $transaction->driver,
                'type' => $transaction->type,
                'description' => $transaction->description,
                'failure_code' => $transaction->failure_code,
                'failure_message' => $transaction->failure_message,
                'receipt_url' => $transaction->receipt_url,
                'metadata' => $transaction->metadata,
                'provider_response' => $transaction->provider_response,
                'user' => $transaction->user ? [
                    'id' => $transaction->user->id,
                    'name' => $transaction->user->name,
                    'email' => $transaction->user->email,
                ] : null,
                'customer' => $transaction->paymentCustomer ? [
                    'id' => $transaction->paymentCustomer->id,
                    'email' => $transaction->paymentCustomer->email,
                    'name' => $transaction->paymentCustomer->name,
                ] : null,
                'refunds' => $transaction->refunds->map(fn ($r) => [
                    'id' => $r->id,
                    'provider_id' => $r->provider_id,
                    'amount' => $r->amount,
                    'formatted_amount' => $r->getFormattedAmount(),
                    'status' => $r->status,
                    'reason' => $r->reason,
                    'created_at' => $r->created_at->toISOString(),
                ]),
                'can_refund' => $transaction->isSuccessful() && $transaction->getRefundableAmount() > 0,
                'created_at' => $transaction->created_at->toISOString(),
                'updated_at' => $transaction->updated_at->toISOString(),
            ],
        ]);
    }

    public function refund(RefundTransactionRequest $request, Transaction $transaction): RedirectResponse
    {

        if (! $transaction->isSuccessful()) {
            return back()->with('error', 'Cannot refund a transaction that is not successful.');
        }

        if ($transaction->getRefundableAmount() <= 0) {
            return back()->with('error', 'This transaction has already been fully refunded.');
        }

        try {
            $amount = $request->get('amount') ?? $transaction->getRefundableAmount();
            $reason = $request->get('reason');

            $result = Payment::driver($transaction->driver)->refund(
                $transaction->provider_id,
                $amount,
                $reason
            );

            if ($result->isSuccessful()) {
                return back()->with('success', 'Refund processed successfully.');
            }

            return back()->with('error', 'Refund failed: '.($result->failureReason ?? 'Unknown error'));
        } catch (\Exception $e) {
            return back()->with('error', 'Refund failed: '.$e->getMessage());
        }
    }
}

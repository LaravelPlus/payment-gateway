<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Nejcc\PaymentGateway\Enums\InvoiceStatus;
use Nejcc\PaymentGateway\Models\Invoice;
use Nejcc\PaymentGateway\Services\InvoicePdfGenerator;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class InvoiceController extends Controller
{
    public function __construct(
        private readonly InvoicePdfGenerator $pdfGenerator,
    ) {}

    public function index(Request $request): Response
    {
        $query = Invoice::with('user')
            ->latest();

        // Filters
        if ($request->filled('status')) {
            $query->where('status', $request->get('status'));
        }

        if ($request->filled('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search): void {
                $q->where('number', 'like', "%{$search}%")
                    ->orWhere('billing_name', 'like', "%{$search}%")
                    ->orWhere('billing_email', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search): void {
                        $uq->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->filled('from')) {
            $query->whereDate('invoice_date', '>=', $request->get('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('invoice_date', '<=', $request->get('to'));
        }

        $invoices = $query->paginate(20)->withQueryString();

        // Summary stats
        $totalPaid = Invoice::where('status', InvoiceStatus::Paid->value)->sum('total');
        $totalOpen = Invoice::where('status', InvoiceStatus::Open->value)->sum('amount_due');
        $totalOverdue = Invoice::where('status', InvoiceStatus::Open->value)
            ->whereNotNull('due_date')
            ->where('due_date', '<', now())
            ->sum('amount_due');

        return Inertia::render('admin/payments/invoices/Index', [
            'invoices' => $invoices->through(fn (Invoice $i) => [
                'id' => $i->id,
                'uuid' => $i->uuid,
                'number' => $i->number,
                'status' => $i->status,
                'total' => $i->total,
                'formatted_total' => $i->formatted_total,
                'amount_due' => $i->amount_due,
                'formatted_amount_due' => $i->formatted_amount_due,
                'currency' => $i->currency,
                'billing_name' => $i->billing_name,
                'billing_email' => $i->billing_email,
                'invoice_date' => $i->invoice_date->toISOString(),
                'due_date' => $i->due_date?->toISOString(),
                'paid_at' => $i->paid_at?->toISOString(),
                'is_overdue' => $i->isOverdue(),
                'has_pdf' => $i->hasPdf(),
                'user' => $i->user ? [
                    'id' => $i->user->id,
                    'name' => $i->user->name,
                    'email' => $i->user->email,
                ] : null,
            ]),
            'filters' => $request->only(['status', 'search', 'from', 'to']),
            'statuses' => InvoiceStatus::cases(),
            'summary' => [
                'total_paid' => $totalPaid,
                'formatted_total_paid' => $this->formatMoney($totalPaid, config('payment-gateway.currency', 'USD')),
                'total_open' => $totalOpen,
                'formatted_total_open' => $this->formatMoney($totalOpen, config('payment-gateway.currency', 'USD')),
                'total_overdue' => $totalOverdue,
                'formatted_total_overdue' => $this->formatMoney($totalOverdue, config('payment-gateway.currency', 'USD')),
            ],
        ]);
    }

    public function show(Invoice $invoice): Response
    {
        $invoice->load(['user', 'transaction', 'subscription']);

        return Inertia::render('admin/payments/invoices/Show', [
            'invoice' => [
                'id' => $invoice->id,
                'uuid' => $invoice->uuid,
                'number' => $invoice->number,
                'status' => $invoice->status,
                'driver' => $invoice->driver,
                'provider_id' => $invoice->provider_id,
                'subtotal' => $invoice->subtotal,
                'formatted_subtotal' => $invoice->formatted_subtotal,
                'tax' => $invoice->tax,
                'formatted_tax' => $invoice->formatted_tax,
                'tax_rate' => $invoice->tax_rate,
                'discount' => $invoice->discount,
                'formatted_discount' => $invoice->formatted_discount,
                'total' => $invoice->total,
                'formatted_total' => $invoice->formatted_total,
                'amount_paid' => $invoice->amount_paid,
                'amount_due' => $invoice->amount_due,
                'formatted_amount_due' => $invoice->formatted_amount_due,
                'currency' => $invoice->currency,
                'billing_name' => $invoice->billing_name,
                'billing_email' => $invoice->billing_email,
                'billing_address' => $invoice->billing_address,
                'billing_city' => $invoice->billing_city,
                'billing_state' => $invoice->billing_state,
                'billing_postal_code' => $invoice->billing_postal_code,
                'billing_country' => $invoice->billing_country,
                'billing_company' => $invoice->billing_company,
                'billing_address_full' => $invoice->billing_address_full,
                'tax_id' => $invoice->tax_id,
                'line_items' => collect($invoice->line_items ?? [])->map(fn ($item) => [
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'formatted_unit_price' => $this->formatMoney($item['unit_price'], $invoice->currency),
                    'amount' => $item['amount'],
                    'formatted_amount' => $this->formatMoney($item['amount'], $invoice->currency),
                ]),
                'notes' => $invoice->notes,
                'footer' => $invoice->footer,
                'invoice_date' => $invoice->invoice_date->toISOString(),
                'due_date' => $invoice->due_date?->toISOString(),
                'paid_at' => $invoice->paid_at?->toISOString(),
                'voided_at' => $invoice->voided_at?->toISOString(),
                'is_paid' => $invoice->isPaid(),
                'is_overdue' => $invoice->isOverdue(),
                'is_voided' => $invoice->isVoided(),
                'has_pdf' => $invoice->hasPdf(),
                'pdf_url' => $invoice->getPdfUrl(),
                'pdf_generated_at' => $invoice->pdf_generated_at?->toISOString(),
                'metadata' => $invoice->metadata,
                'user' => $invoice->user ? [
                    'id' => $invoice->user->id,
                    'name' => $invoice->user->name,
                    'email' => $invoice->user->email,
                ] : null,
                'transaction' => $invoice->transaction ? [
                    'id' => $invoice->transaction->id,
                    'uuid' => $invoice->transaction->uuid,
                    'status' => $invoice->transaction->status,
                ] : null,
                'subscription' => $invoice->subscription ? [
                    'id' => $invoice->subscription->id,
                    'uuid' => $invoice->subscription->uuid,
                    'status' => $invoice->subscription->status,
                ] : null,
                'created_at' => $invoice->created_at->toISOString(),
                'updated_at' => $invoice->updated_at->toISOString(),
            ],
        ]);
    }

    public function download(Invoice $invoice): StreamedResponse
    {
        if (! $invoice->hasPdf()) {
            // Generate PDF if it doesn't exist
            $this->pdfGenerator->generate($invoice);
            $invoice->refresh();
        }

        if (! $invoice->hasPdf()) {
            abort(404, 'Invoice PDF not found.');
        }

        return Storage::download(
            $invoice->pdf_path,
            "invoice-{$invoice->number}.pdf",
            ['Content-Type' => 'application/pdf']
        );
    }

    public function regeneratePdf(Invoice $invoice): RedirectResponse
    {
        try {
            $this->pdfGenerator->generate($invoice);

            return back()->with('success', 'Invoice PDF regenerated successfully.');
        } catch (\Exception $e) {
            return back()->with('error', 'Failed to regenerate PDF: '.$e->getMessage());
        }
    }

    private function formatMoney(int $cents, string $currency): string
    {
        $formatter = new \NumberFormatter('en', \NumberFormatter::CURRENCY);

        return $formatter->formatCurrency($cents / 100, $currency);
    }
}

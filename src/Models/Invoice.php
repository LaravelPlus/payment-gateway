<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Nejcc\PaymentGateway\Database\Factories\InvoiceFactory;
use Nejcc\PaymentGateway\Enums\InvoiceStatus;
use NumberFormatter;

/**
 * Payment Invoice Model.
 *
 * All monetary amounts are stored in cents (smallest currency unit).
 */
final class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    protected $table = 'payment_invoices';

    protected $fillable = [
        'uuid',
        'user_id',
        'payment_customer_id',
        'subscription_id',
        'transaction_id',
        'number',
        'driver',
        'provider_id',
        'status',
        'subtotal',
        'tax',
        'discount',
        'total',
        'amount_paid',
        'amount_due',
        'currency',
        'tax_rate',
        'tax_id',
        'billing_name',
        'billing_email',
        'billing_address',
        'billing_city',
        'billing_state',
        'billing_postal_code',
        'billing_country',
        'billing_company',
        'invoice_date',
        'due_date',
        'paid_at',
        'voided_at',
        'pdf_path',
        'pdf_generated_at',
        'line_items',
        'notes',
        'footer',
        'metadata',
        'provider_response',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'integer',
            'tax' => 'integer',
            'discount' => 'integer',
            'total' => 'integer',
            'amount_paid' => 'integer',
            'amount_due' => 'integer',
            'tax_rate' => 'decimal:2',
            'invoice_date' => 'date',
            'due_date' => 'date',
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
            'pdf_generated_at' => 'datetime',
            'line_items' => 'array',
            'metadata' => 'array',
            'provider_response' => 'array',
        ];
    }

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        self::creating(function (self $invoice): void {
            if (empty($invoice->uuid)) {
                $invoice->uuid = (string) Str::uuid();
            }
            if (empty($invoice->number)) {
                $invoice->number = self::generateInvoiceNumber();
            }
            if (empty($invoice->invoice_date)) {
                $invoice->invoice_date = now();
            }
            if (empty($invoice->status)) {
                $invoice->status = InvoiceStatus::Draft->value;
            }
        });
    }

    // ========================================
    // Relationships
    // ========================================

    /**
     * Get the user that owns this invoice.
     */
    public function user(): BelongsTo
    {
        $userModel = config('payment-gateway.billable_model', 'App\\Models\\User');

        return $this->belongsTo($userModel);
    }

    /**
     * Get the payment customer.
     */
    public function paymentCustomer(): BelongsTo
    {
        return $this->belongsTo(PaymentCustomer::class, 'payment_customer_id');
    }

    /**
     * Get the subscription.
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * Get the transaction.
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    // ========================================
    // Scopes
    // ========================================

    /**
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    public function scopePaid(Builder $query): Builder
    {
        return $query->where('status', InvoiceStatus::Paid->value);
    }

    /**
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', InvoiceStatus::Open->value);
    }

    /**
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', InvoiceStatus::Open->value)
            ->whereNotNull('due_date')
            ->where('due_date', '<', now());
    }

    /**
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    public function scopeThisMonth(Builder $query): Builder
    {
        return $query->whereMonth('invoice_date', now()->month)
            ->whereYear('invoice_date', now()->year);
    }

    /**
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    public function scopeThisYear(Builder $query): Builder
    {
        return $query->whereYear('invoice_date', now()->year);
    }

    // ========================================
    // Accessors
    // ========================================

    /**
     * Get status enum.
     */
    public function getStatusEnum(): InvoiceStatus
    {
        return InvoiceStatus::from($this->status);
    }

    /**
     * Get formatted subtotal.
     */
    public function getFormattedSubtotalAttribute(): string
    {
        return $this->formatAmount($this->subtotal);
    }

    /**
     * Get formatted tax.
     */
    public function getFormattedTaxAttribute(): string
    {
        return $this->formatAmount($this->tax);
    }

    /**
     * Get formatted discount.
     */
    public function getFormattedDiscountAttribute(): string
    {
        return $this->formatAmount($this->discount);
    }

    /**
     * Get formatted total.
     */
    public function getFormattedTotalAttribute(): string
    {
        return $this->formatAmount($this->total);
    }

    /**
     * Get formatted amount due.
     */
    public function getFormattedAmountDueAttribute(): string
    {
        return $this->formatAmount($this->amount_due);
    }

    /**
     * Get full billing address.
     */
    public function getBillingAddressFullAttribute(): string
    {
        return collect([
            $this->billing_address,
            $this->billing_city,
            $this->billing_state,
            $this->billing_postal_code,
            $this->billing_country,
        ])->filter()->implode(', ');
    }

    /**
     * Check if invoice is paid.
     */
    public function isPaid(): bool
    {
        return $this->status === InvoiceStatus::Paid->value;
    }

    /**
     * Check if invoice is overdue.
     */
    public function isOverdue(): bool
    {
        return $this->status === InvoiceStatus::Open->value
            && $this->due_date !== null
            && $this->due_date->isPast();
    }

    /**
     * Check if invoice is voided.
     */
    public function isVoided(): bool
    {
        return $this->status === InvoiceStatus::Void->value;
    }

    // ========================================
    // Methods
    // ========================================

    /**
     * Mark invoice as paid.
     */
    public function markAsPaid(?int $amountPaid = null): self
    {
        $this->update([
            'status' => InvoiceStatus::Paid->value,
            'amount_paid' => $amountPaid ?? $this->total,
            'amount_due' => 0,
            'paid_at' => now(),
        ]);

        return $this;
    }

    /**
     * Mark invoice as void.
     */
    public function markAsVoid(): self
    {
        $this->update([
            'status' => InvoiceStatus::Void->value,
            'voided_at' => now(),
        ]);

        return $this;
    }

    /**
     * Finalize invoice (move from draft to open).
     */
    public function finalize(): self
    {
        if ($this->status !== InvoiceStatus::Draft->value) {
            return $this;
        }

        $this->update([
            'status' => InvoiceStatus::Open->value,
        ]);

        return $this;
    }

    /**
     * Add a line item.
     *
     * @param  array<string, mixed>  $item
     */
    public function addLineItem(array $item): self
    {
        $lineItems = $this->line_items ?? [];
        $lineItems[] = [
            'description' => $item['description'],
            'quantity' => $item['quantity'] ?? 1,
            'unit_price' => $item['unit_price'], // in cents
            'amount' => ($item['quantity'] ?? 1) * $item['unit_price'],
        ];

        $this->line_items = $lineItems;
        $this->recalculateTotals();

        return $this;
    }

    /**
     * Recalculate totals from line items.
     */
    public function recalculateTotals(): self
    {
        $subtotal = collect($this->line_items ?? [])->sum('amount');

        $tax = 0;
        if ($this->tax_rate) {
            $tax = (int) round($subtotal * ($this->tax_rate / 100));
        }

        $total = $subtotal + $tax - ($this->discount ?? 0);
        $amountDue = $total - ($this->amount_paid ?? 0);

        $this->subtotal = $subtotal;
        $this->tax = $tax;
        $this->total = $total;
        $this->amount_due = max(0, $amountDue);

        return $this;
    }

    /**
     * Get PDF download URL.
     */
    public function getPdfUrl(): ?string
    {
        if (empty($this->pdf_path)) {
            return null;
        }

        return Storage::url($this->pdf_path);
    }

    /**
     * Check if PDF exists.
     */
    public function hasPdf(): bool
    {
        return !empty($this->pdf_path) && Storage::exists($this->pdf_path);
    }

    /**
     * Format amount in currency.
     */
    private function formatAmount(int $amount): string
    {
        $formatter = new NumberFormatter('en', NumberFormatter::CURRENCY);

        return $formatter->formatCurrency($amount / 100, $this->currency);
    }

    /**
     * Generate unique invoice number.
     */
    public static function generateInvoiceNumber(): string
    {
        $year = now()->format('Y');
        $prefix = config('payment-gateway.invoice.prefix', 'INV');

        $lastInvoice = self::whereYear('created_at', $year)
            ->orderByDesc('id')
            ->first();

        if ($lastInvoice && preg_match('/(\d+)$/', $lastInvoice->number, $matches)) {
            $sequence = (int) $matches[1] + 1;
        } else {
            $sequence = 1;
        }

        return sprintf('%s-%s-%04d', $prefix, $year, $sequence);
    }

    /**
     * Create invoice from transaction.
     */
    public static function createFromTransaction(Transaction $transaction): self
    {
        $user = $transaction->user;

        return self::create([
            'user_id' => $transaction->user_id,
            'payment_customer_id' => $transaction->payment_customer_id,
            'transaction_id' => $transaction->id,
            'driver' => $transaction->driver,
            'provider_id' => $transaction->provider_id,
            'status' => $transaction->isSuccessful() ? InvoiceStatus::Paid->value : InvoiceStatus::Open->value,
            'subtotal' => $transaction->amount,
            'total' => $transaction->amount,
            'amount_paid' => $transaction->isSuccessful() ? $transaction->amount : 0,
            'amount_due' => $transaction->isSuccessful() ? 0 : $transaction->amount,
            'currency' => $transaction->currency,
            'billing_name' => $user->name ?? null,
            'billing_email' => $user->email ?? null,
            'invoice_date' => now(),
            'paid_at' => $transaction->isSuccessful() ? now() : null,
            'line_items' => [
                [
                    'description' => $transaction->description ?? 'Payment',
                    'quantity' => 1,
                    'unit_price' => $transaction->amount,
                    'amount' => $transaction->amount,
                ],
            ],
        ]);
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): InvoiceFactory
    {
        return InvoiceFactory::new();
    }
}

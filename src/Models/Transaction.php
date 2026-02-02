<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;
use Nejcc\PaymentGateway\Database\Factories\TransactionFactory;
use Nejcc\PaymentGateway\DTOs\PaymentResult;
use Nejcc\PaymentGateway\Enums\PaymentStatus;
use NumberFormatter;

final class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

    protected $table = 'payment_transactions';

    protected $fillable = [
        'uuid',
        'user_id',
        'payment_customer_id',
        'driver',
        'provider_id',
        'provider_payment_method_id',
        'amount',
        'amount_refunded',
        'currency',
        'status',
        'type',
        'description',
        'failure_code',
        'failure_message',
        'receipt_url',
        'payable_type',
        'payable_id',
        'metadata',
        'provider_response',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'amount_refunded' => 'integer',
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

        self::creating(function (self $model): void {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Get the user that owns this transaction.
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
     * Get the payable model (order, subscription, etc.).
     */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get refunds for this transaction.
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class, 'transaction_id');
    }

    /**
     * Get the payment status enum.
     */
    public function getStatusEnum(): PaymentStatus
    {
        return PaymentStatus::from($this->status);
    }

    /**
     * Check if transaction is successful.
     */
    public function isSuccessful(): bool
    {
        return $this->getStatusEnum()->isSuccessful();
    }

    /**
     * Check if transaction is pending.
     */
    public function isPending(): bool
    {
        return $this->getStatusEnum()->isPending();
    }

    /**
     * Check if transaction has failed.
     */
    public function isFailed(): bool
    {
        return $this->getStatusEnum()->isFailed();
    }

    /**
     * Check if transaction is refunded.
     */
    public function isRefunded(): bool
    {
        return $this->status === PaymentStatus::Refunded->value;
    }

    /**
     * Check if transaction is partially refunded.
     */
    public function isPartiallyRefunded(): bool
    {
        return $this->amount_refunded > 0 && $this->amount_refunded < $this->amount;
    }

    /**
     * Get refundable amount (in cents).
     */
    public function getRefundableAmount(): int
    {
        return $this->amount - $this->amount_refunded;
    }

    /**
     * Get amount in decimal format.
     */
    public function getAmountDecimal(): float
    {
        return $this->amount / 100;
    }

    /**
     * Get formatted amount.
     */
    public function getFormattedAmount(): string
    {
        $formatter = new NumberFormatter('en', NumberFormatter::CURRENCY);

        return $formatter->formatCurrency($this->getAmountDecimal(), $this->currency);
    }

    /**
     * Convert to PaymentResult DTO.
     */
    public function toDto(): PaymentResult
    {
        return new PaymentResult(
            transactionId: $this->provider_id ?? $this->uuid,
            status: $this->getStatusEnum(),
            amount: $this->amount,
            currency: $this->currency,
            driver: $this->driver,
            paymentMethodId: $this->provider_payment_method_id,
            customerId: $this->paymentCustomer?->getProviderId($this->driver),
            failureCode: $this->failure_code,
            failureMessage: $this->failure_message,
            receiptUrl: $this->receipt_url,
            metadata: $this->metadata ?? [],
            raw: $this->provider_response ?? [],
        );
    }

    /**
     * Create from PaymentResult DTO.
     *
     * @param  array<string, mixed>  $extra
     */
    public static function fromDto(PaymentResult $result, array $extra = []): static
    {
        return new self(array_merge([
            'driver' => $result->driver,
            'provider_id' => $result->transactionId,
            'provider_payment_method_id' => $result->paymentMethodId,
            'amount' => $result->amount,
            'currency' => $result->currency,
            'status' => $result->status->value,
            'failure_code' => $result->failureCode,
            'failure_message' => $result->failureMessage,
            'receipt_url' => $result->receiptUrl,
            'metadata' => $result->metadata,
            'provider_response' => $result->raw,
        ], $extra));
    }

    /**
     * Scope to filter by status.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeStatus(\Illuminate\Database\Eloquent\Builder $query, PaymentStatus|string $status): \Illuminate\Database\Eloquent\Builder
    {
        $value = $status instanceof PaymentStatus ? $status->value : $status;

        return $query->where('status', $value);
    }

    /**
     * Scope to filter successful transactions.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeSuccessful(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('status', PaymentStatus::Succeeded->value);
    }

    /**
     * Scope to filter by driver.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeDriver(\Illuminate\Database\Eloquent\Builder $query, string $driver): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('driver', $driver);
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): TransactionFactory
    {
        return TransactionFactory::new();
    }
}

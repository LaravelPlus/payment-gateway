<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Nejcc\PaymentGateway\Database\Factories\RefundFactory;
use Nejcc\PaymentGateway\DTOs\Refund as RefundDto;
use NumberFormatter;

final class Refund extends Model
{
    /** @use HasFactory<RefundFactory> */
    use HasFactory;

    protected $table = 'payment_refunds';

    protected $fillable = [
        'uuid',
        'transaction_id',
        'user_id',
        'driver',
        'provider_id',
        'amount',
        'currency',
        'status',
        'reason',
        'failure_reason',
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

        self::created(function (self $model): void {
            // Update the transaction's refunded amount
            $model->transaction->increment('amount_refunded', $model->amount);

            // Update transaction status if fully refunded
            if ($model->transaction->amount_refunded >= $model->transaction->amount) {
                $model->transaction->update(['status' => 'refunded']);
            } elseif ($model->transaction->amount_refunded > 0) {
                $model->transaction->update(['status' => 'partially_refunded']);
            }
        });
    }

    /**
     * Get the transaction this refund belongs to.
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }

    /**
     * Get the user.
     */
    public function user(): BelongsTo
    {
        $userModel = config('payment-gateway.billable_model', 'App\\Models\\User');

        return $this->belongsTo($userModel);
    }

    /**
     * Check if refund is successful.
     */
    public function isSuccessful(): bool
    {
        return $this->status === 'succeeded';
    }

    /**
     * Check if refund is pending.
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if refund failed.
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
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
     * Convert to DTO.
     */
    public function toDto(): RefundDto
    {
        return new RefundDto(
            id: $this->provider_id ?? $this->uuid,
            transactionId: $this->transaction?->provider_id ?? (string) $this->transaction_id,
            status: $this->status,
            amount: $this->amount,
            currency: $this->currency,
            driver: $this->driver,
            reason: $this->reason,
            failureReason: $this->failure_reason,
            createdAt: $this->created_at,
            metadata: $this->metadata ?? [],
            raw: $this->provider_response ?? [],
        );
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): RefundFactory
    {
        return RefundFactory::new();
    }
}

<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Nejcc\PaymentGateway\Database\Factories\PaymentMethodFactory;

final class PaymentMethod extends Model
{
    /** @use HasFactory<PaymentMethodFactory> */
    use HasFactory;

    protected $table = 'payment_methods';

    protected $fillable = [
        'uuid',
        'user_id',
        'payment_customer_id',
        'driver',
        'provider_id',
        'type',
        'card_brand',
        'card_last_four',
        'card_exp_month',
        'card_exp_year',
        'bank_name',
        'bank_last_four',
        'crypto_currency',
        'crypto_address',
        'paypal_email',
        'billing_address',
        'is_default',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'card_exp_month' => 'integer',
            'card_exp_year' => 'integer',
            'billing_address' => 'array',
            'is_default' => 'boolean',
            'metadata' => 'array',
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
     * Get the user that owns this payment method.
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
     * Check if this is a card.
     */
    public function isCard(): bool
    {
        return $this->type === 'card';
    }

    /**
     * Check if card is expired.
     */
    public function isExpired(): bool
    {
        if (!$this->isCard() || $this->card_exp_year === null || $this->card_exp_month === null) {
            return false;
        }

        $expiry = \Carbon\Carbon::createFromDate($this->card_exp_year, $this->card_exp_month, 1)->endOfMonth();

        return $expiry->isPast();
    }

    /**
     * Get display name for the payment method.
     */
    public function getDisplayName(): string
    {
        return match ($this->type) {
            'card' => ucfirst($this->card_brand ?? 'Card')." •••• {$this->card_last_four}",
            'bank_account' => ($this->bank_name ?? 'Bank')." •••• {$this->bank_last_four}",
            'paypal' => "PayPal ({$this->paypal_email})",
            'crypto' => mb_strtoupper($this->crypto_currency ?? 'Crypto').' Wallet',
            default => ucfirst($this->type),
        };
    }

    /**
     * Get expiry string for cards.
     */
    public function getExpiryString(): ?string
    {
        if (!$this->isCard() || $this->card_exp_month === null || $this->card_exp_year === null) {
            return null;
        }

        return sprintf('%02d/%d', $this->card_exp_month, $this->card_exp_year % 100);
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
     * Scope to filter default methods.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeDefault(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_default', true);
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): PaymentMethodFactory
    {
        return PaymentMethodFactory::new();
    }
}

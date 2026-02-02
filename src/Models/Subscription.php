<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Nejcc\PaymentGateway\Database\Factories\SubscriptionFactory;
use Nejcc\PaymentGateway\DTOs\Subscription as SubscriptionDto;
use Nejcc\PaymentGateway\Enums\SubscriptionStatus;
use NumberFormatter;

final class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    protected $table = 'payment_subscriptions';

    protected $fillable = [
        'uuid',
        'user_id',
        'payment_customer_id',
        'payment_plan_id',
        'driver',
        'provider_id',
        'provider_plan_id',
        'plan_id',
        'plan_name',
        'amount',
        'currency',
        'interval',
        'interval_count',
        'status',
        'quantity',
        'current_period_start',
        'current_period_end',
        'trial_start',
        'trial_end',
        'canceled_at',
        'ended_at',
        'cancel_at_period_end',
        'paused_at',
        'resume_at',
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
            'interval_count' => 'integer',
            'quantity' => 'integer',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'trial_start' => 'datetime',
            'trial_end' => 'datetime',
            'canceled_at' => 'datetime',
            'ended_at' => 'datetime',
            'paused_at' => 'datetime',
            'resume_at' => 'datetime',
            'cancel_at_period_end' => 'boolean',
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
     * Get the user that owns this subscription.
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
     * Get the plan for this subscription.
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'payment_plan_id');
    }

    /**
     * Get the subscription status enum.
     */
    public function getStatusEnum(): SubscriptionStatus
    {
        return SubscriptionStatus::from($this->status);
    }

    /**
     * Check if subscription is active.
     */
    public function isActive(): bool
    {
        return $this->getStatusEnum()->isActive();
    }

    /**
     * Check if subscription is on trial.
     */
    public function onTrial(): bool
    {
        return $this->status === SubscriptionStatus::Trialing->value
            && $this->trial_end !== null
            && $this->trial_end->isFuture();
    }

    /**
     * Check if subscription is canceled.
     */
    public function isCanceled(): bool
    {
        return $this->canceled_at !== null;
    }

    /**
     * Check if subscription is on grace period.
     */
    public function onGracePeriod(): bool
    {
        return $this->cancel_at_period_end
            && $this->current_period_end !== null
            && $this->current_period_end->isFuture();
    }

    /**
     * Check if subscription has ended.
     */
    public function hasEnded(): bool
    {
        return $this->ended_at !== null;
    }

    /**
     * Check if subscription is paused.
     */
    public function isPaused(): bool
    {
        return $this->paused_at !== null && $this->resume_at === null;
    }

    /**
     * Get days remaining in current period.
     */
    public function daysRemaining(): int
    {
        if ($this->current_period_end === null) {
            return 0;
        }

        return max(0, now()->diffInDays($this->current_period_end, false));
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
     * Get billing description.
     */
    public function getBillingDescription(): string
    {
        $amount = $this->getFormattedAmount();
        $interval = $this->interval_count > 1
            ? "{$this->interval_count} {$this->interval}s"
            : $this->interval;

        return "{$amount} / {$interval}";
    }

    /**
     * Scope to filter active subscriptions.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeActive(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereIn('status', [
            SubscriptionStatus::Active->value,
            SubscriptionStatus::Trialing->value,
        ]);
    }

    /**
     * Scope to filter by plan.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopePlan(\Illuminate\Database\Eloquent\Builder $query, string $planId): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('plan_id', $planId);
    }

    /**
     * Scope to filter expiring soon.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeExpiringSoon(\Illuminate\Database\Eloquent\Builder $query, int $days = 7): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereBetween('current_period_end', [now(), now()->addDays($days)]);
    }

    /**
     * Convert to DTO.
     */
    public function toDto(): SubscriptionDto
    {
        return new SubscriptionDto(
            id: $this->provider_id ?? $this->uuid,
            customerId: $this->paymentCustomer?->stripe_id ?? (string) $this->user_id,
            planId: $this->provider_plan_id ?? (string) $this->payment_plan_id,
            status: $this->getStatusEnum(),
            amount: $this->amount,
            currency: $this->currency,
            interval: $this->interval,
            driver: $this->driver,
            currentPeriodStart: $this->current_period_start,
            currentPeriodEnd: $this->current_period_end,
            trialStart: $this->trial_start,
            trialEnd: $this->trial_end,
            canceledAt: $this->canceled_at,
            endedAt: $this->ended_at,
            cancelAtPeriodEnd: $this->cancel_at_period_end,
            metadata: $this->metadata ?? [],
            raw: $this->provider_response ?? [],
        );
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): SubscriptionFactory
    {
        return SubscriptionFactory::new();
    }
}

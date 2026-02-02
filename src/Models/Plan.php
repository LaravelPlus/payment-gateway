<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Nejcc\PaymentGateway\Database\Factories\PlanFactory;
use Nejcc\PaymentGateway\DTOs\SubscriptionPlan;
use NumberFormatter;

/**
 * Payment Plan Model.
 *
 * All monetary amounts are stored in cents (smallest currency unit).
 *
 * @property int $id
 * @property string $uuid
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property int $amount
 * @property string $currency
 * @property string $interval
 * @property int $interval_count
 * @property int|null $trial_days
 * @property array|null $features
 * @property array|null $limits
 * @property array|null $metadata
 * @property string|null $stripe_price_id
 * @property string|null $stripe_product_id
 * @property string|null $paypal_plan_id
 * @property bool $is_active
 * @property bool $is_featured
 * @property int $sort_order
 * @property bool $is_public
 * @property bool $is_archived
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 */
final class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'payment_plans';

    protected $fillable = [
        'slug',
        'name',
        'description',
        'amount',
        'currency',
        'interval',
        'interval_count',
        'trial_days',
        'features',
        'limits',
        'metadata',
        'stripe_price_id',
        'stripe_product_id',
        'paypal_plan_id',
        'is_active',
        'is_featured',
        'sort_order',
        'is_public',
        'is_archived',
    ];

    /**
     * Get the columns that should receive a unique identifier.
     *
     * @return array<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'interval_count' => 'integer',
            'trial_days' => 'integer',
            'features' => 'array',
            'limits' => 'array',
            'metadata' => 'array',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'sort_order' => 'integer',
            'is_public' => 'boolean',
            'is_archived' => 'boolean',
        ];
    }

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        self::creating(function (Plan $plan): void {
            if (empty($plan->slug)) {
                $plan->slug = Str::slug($plan->name);
            }
            if (empty($plan->currency)) {
                $plan->currency = config('payment-gateway.currency', 'EUR');
            }
        });
    }

    // ========================================
    // Relationships
    // ========================================

    /**
     * Get subscriptions for this plan.
     *
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'payment_plan_id');
    }

    // ========================================
    // Scopes
    // ========================================

    /**
     * Scope to only active plans.
     *
     * @param  Builder<Plan>  $query
     * @return Builder<Plan>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_archived', false);
    }

    /**
     * Scope to only public plans.
     *
     * @param  Builder<Plan>  $query
     * @return Builder<Plan>
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_public', true);
    }

    /**
     * Scope to only featured plans.
     *
     * @param  Builder<Plan>  $query
     * @return Builder<Plan>
     */
    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('is_featured', true);
    }

    /**
     * Scope to plans with a specific interval.
     *
     * @param  Builder<Plan>  $query
     * @return Builder<Plan>
     */
    public function scopeInterval(Builder $query, string $interval): Builder
    {
        return $query->where('interval', $interval);
    }

    /**
     * Scope to monthly plans.
     *
     * @param  Builder<Plan>  $query
     * @return Builder<Plan>
     */
    public function scopeMonthly(Builder $query): Builder
    {
        return $query->where('interval', 'month')->where('interval_count', 1);
    }

    /**
     * Scope to yearly plans.
     *
     * @param  Builder<Plan>  $query
     * @return Builder<Plan>
     */
    public function scopeYearly(Builder $query): Builder
    {
        return $query->where('interval', 'year')->where('interval_count', 1);
    }

    /**
     * Scope ordered by sort order.
     *
     * @param  Builder<Plan>  $query
     * @return Builder<Plan>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('amount');
    }

    /**
     * Scope to only free plans (amount = 0).
     *
     * @param  Builder<Plan>  $query
     * @return Builder<Plan>
     */
    public function scopeFree(Builder $query): Builder
    {
        return $query->where('amount', 0);
    }

    /**
     * Scope to only paid plans (amount > 0).
     *
     * @param  Builder<Plan>  $query
     * @return Builder<Plan>
     */
    public function scopePaid(Builder $query): Builder
    {
        return $query->where('amount', '>', 0);
    }

    /**
     * Scope to plans with trial.
     *
     * @param  Builder<Plan>  $query
     * @return Builder<Plan>
     */
    public function scopeWithTrial(Builder $query): Builder
    {
        return $query->whereNotNull('trial_days')->where('trial_days', '>', 0);
    }

    // ========================================
    // Accessors
    // ========================================

    /**
     * Get amount in decimal format.
     */
    public function getAmountDecimalAttribute(): float
    {
        return $this->amount / 100;
    }

    /**
     * Get formatted price.
     */
    public function getFormattedPriceAttribute(): string
    {
        $formatter = new NumberFormatter('en', NumberFormatter::CURRENCY);

        return $formatter->formatCurrency($this->amount / 100, $this->currency);
    }

    /**
     * Get billing description (e.g., "$9.99 / month" or "Free").
     */
    public function getBillingDescriptionAttribute(): string
    {
        if ($this->is_free) {
            return 'Free';
        }

        $price = $this->formatted_price;
        $interval = $this->interval_count > 1
            ? "{$this->interval_count} {$this->interval}s"
            : $this->interval;

        return "{$price} / {$interval}";
    }

    /**
     * Get human-readable interval label.
     */
    public function getIntervalLabelAttribute(): string
    {
        if ($this->interval_count === 1) {
            return match ($this->interval) {
                'day' => 'Daily',
                'week' => 'Weekly',
                'month' => 'Monthly',
                'year' => 'Yearly',
                default => ucfirst($this->interval),
            };
        }

        return "Every {$this->interval_count} {$this->interval}s";
    }

    /**
     * Check if plan is free.
     */
    public function getIsFreeAttribute(): bool
    {
        return $this->amount === 0;
    }

    /**
     * Get trial days (use default from config if not set on plan).
     */
    public function getEffectiveTrialDaysAttribute(): int
    {
        if ($this->trial_days !== null) {
            return $this->trial_days;
        }

        return (int) config('payment-gateway.subscriptions.default_trial_days', 0);
    }

    /**
     * Get trial description.
     */
    public function getTrialDescriptionAttribute(): ?string
    {
        $days = $this->effective_trial_days;

        if ($days <= 0) {
            return null;
        }

        if ($days === 30) {
            return '1 month free trial';
        }

        if ($days === 60) {
            return '2 months free trial';
        }

        return "{$days} days free trial";
    }

    // ========================================
    // Methods
    // ========================================

    /**
     * Check if plan has a specific feature.
     */
    public function hasFeature(string $feature): bool
    {
        return in_array($feature, $this->features ?? [], true);
    }

    /**
     * Get a specific limit value.
     */
    public function getLimit(string $key, mixed $default = null): mixed
    {
        return $this->limits[$key] ?? $default;
    }

    /**
     * Check if plan is free (amount = 0).
     */
    public function isFree(): bool
    {
        return $this->amount === 0;
    }

    /**
     * Check if plan is paid (amount > 0).
     */
    public function isPaid(): bool
    {
        return $this->amount > 0;
    }

    /**
     * Check if plan has trials.
     */
    public function hasTrial(): bool
    {
        return $this->effective_trial_days > 0;
    }

    /**
     * Get provider price ID based on driver.
     */
    public function getProviderPriceId(string $driver): ?string
    {
        return match ($driver) {
            'stripe' => $this->stripe_price_id,
            'paypal' => $this->paypal_plan_id,
            default => null,
        };
    }

    /**
     * Convert to DTO.
     */
    public function toDto(): SubscriptionPlan
    {
        return new SubscriptionPlan(
            id: (string) $this->id,
            productId: $this->stripe_product_id ?? $this->slug,
            name: $this->name,
            amount: $this->amount,
            currency: $this->currency,
            interval: $this->interval,
            intervalCount: $this->interval_count,
            driver: 'local',
            description: $this->description,
            trialDays: $this->trial_days,
            features: $this->features ?? [],
            isActive: $this->is_active,
            metadata: $this->metadata ?? [],
        );
    }

    /**
     * Find plan by slug.
     */
    public static function findBySlug(string $slug): ?self
    {
        return self::where('slug', $slug)->first();
    }

    /**
     * Get all public, active plans for pricing page.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Plan>
     */
    public static function forPricingPage(): \Illuminate\Database\Eloquent\Collection
    {
        return self::query()
            ->active()
            ->public()
            ->ordered()
            ->get();
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): PlanFactory
    {
        return PlanFactory::new();
    }
}

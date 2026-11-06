<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LaravelPlus\PaymentGateway\DTOs\Subscription;

/**
 * Dispatched once per configured reminder lead (payment-gateway.notifications.trial_reminder_days)
 * before a trial ends. Fired by Stripe's trial_will_end webhook and by the
 * payment-gateway:trial-reminders command, deduplicated across both.
 */
final class SubscriptionTrialWillEnd
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Subscription $subscription,
        public readonly int $daysRemaining,
    ) {}
}

<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LaravelPlus\PaymentGateway\DTOs\Subscription;

/**
 * Dispatched once when a trial ends, whatever the outcome. $subscription->status says which:
 * Active (converted, first invoice paid), PastDue/Unpaid/Incomplete (first charge failed),
 * Canceled or Paused (no payment method, per trial_end_behavior, or canceled during the trial).
 */
final class SubscriptionTrialEnded
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Subscription $subscription,
    ) {}
}

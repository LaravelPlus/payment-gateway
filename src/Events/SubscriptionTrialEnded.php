<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LaravelPlus\PaymentGateway\DTOs\Subscription;

/**
 * Dispatched once when a trial ends, whatever the outcome. $subscription->status says which:
 * Active (the first invoice was created; on Stripe it may still fail and move the subscription
 * to PastDue in a later webhook), Canceled or Paused (no payment
 * method, per trial_end_behavior, or canceled during the trial).
 */
final class SubscriptionTrialEnded
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Subscription $subscription,
    ) {}
}

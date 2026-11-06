<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LaravelPlus\PaymentGateway\DTOs\Subscription;

/**
 * Dispatched once when a subscription first starts a trial.
 */
final class SubscriptionTrialStarted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Subscription $subscription,
    ) {}
}

<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LaravelPlus\PaymentGateway\DTOs\PaymentResult;

/**
 * Dispatched when a payment fails.
 */
final class PaymentFailed
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly PaymentResult $payment,
        public readonly ?string $reason = null,
    ) {}
}

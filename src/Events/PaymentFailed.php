<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Nejcc\PaymentGateway\DTOs\PaymentResult;

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

<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Nejcc\PaymentGateway\DTOs\Refund;

/**
 * Dispatched when a refund is processed.
 */
final class RefundProcessed
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Refund $refund,
    ) {}
}

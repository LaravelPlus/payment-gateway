<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Dispatched when a payment webhook handling fails.
 */
final class WebhookHandleFailed
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $driver,
        public readonly Throwable $exception,
    ) {}
}

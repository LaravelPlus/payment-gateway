<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Nejcc\PaymentGateway\DTOs\WebhookPayload;

/**
 * Dispatched when a payment webhook is received.
 */
final class PaymentWebhookReceived
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly WebhookPayload $payload,
    ) {}
}

<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Nejcc\PaymentGateway\DTOs\WebhookPayload;

/**
 * Dispatched when a payment webhook has been successfully handled.
 */
final class WebhookHandled
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $result
     */
    public function __construct(
        public readonly WebhookPayload $payload,
        public readonly array $result,
    ) {}
}

<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Contracts;

use Illuminate\Http\Request;
use Nejcc\PaymentGateway\DTOs\WebhookPayload;

interface SupportsWebhooks
{
    /**
     * Verify webhook signature.
     */
    public function verifyWebhookSignature(Request $request): bool;

    /**
     * Parse webhook payload.
     */
    public function parseWebhook(Request $request): WebhookPayload;

    /**
     * Get the webhook secret.
     */
    public function getWebhookSecret(): ?string;

    /**
     * Handle webhook event.
     *
     * @return array<string, mixed>
     */
    public function handleWebhook(WebhookPayload $payload): array;
}

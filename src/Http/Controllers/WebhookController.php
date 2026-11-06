<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LaravelPlus\PaymentGateway\Contracts\SupportsWebhooks;
use LaravelPlus\PaymentGateway\Events\PaymentWebhookReceived;
use LaravelPlus\PaymentGateway\Events\WebhookHandled;
use LaravelPlus\PaymentGateway\Events\WebhookHandleFailed;
use LaravelPlus\PaymentGateway\Exceptions\InvalidWebhookPayloadException;
use LaravelPlus\PaymentGateway\Facades\Payment;
use Throwable;

final class WebhookController extends Controller
{
    /**
     * Handle Stripe webhook.
     */
    public function stripe(Request $request): JsonResponse
    {
        return $this->handleWebhook($request, 'stripe');
    }

    /**
     * Handle PayPal webhook.
     */
    public function paypal(Request $request): JsonResponse
    {
        return $this->handleWebhook($request, 'paypal');
    }

    /**
     * Handle Crypto webhook.
     */
    public function crypto(Request $request): JsonResponse
    {
        return $this->handleWebhook($request, 'crypto');
    }

    /**
     * Handle incoming webhook.
     */
    protected function handleWebhook(Request $request, string $driver): JsonResponse
    {
        // Validate content type
        $contentType = $request->header('Content-Type', '');
        if (!str_contains($contentType, 'application/json')) {
            return response()->json(['error' => 'Invalid content type'], 415);
        }

        // Max 1MB. The header is only a cheap early-out: it is client-supplied
        // and may be missing or wrong, so the real body length decides.
        // ponytail: PHP has already buffered the body by now; a hard cap belongs
        // in the web server (client_max_body_size / post_max_size).
        $maxSize = 1048576;
        if ((int) $request->header('Content-Length', '0') > $maxSize) {
            return response()->json(['error' => 'Payload too large'], 413);
        }

        $content = $request->getContent();
        if (strlen($content) > $maxSize) {
            return response()->json(['error' => 'Payload too large'], 413);
        }

        // Every provider sends a JSON object; arrays, scalars and null are not events.
        if (!json_decode($content) instanceof \stdClass) {
            return response()->json(['error' => 'Invalid JSON payload'], 400);
        }

        try {
            $gateway = Payment::driver($driver);

            if (!$gateway instanceof SupportsWebhooks) {
                return response()->json(['error' => 'Webhooks not supported'], 400);
            }

            // Verify signature
            if (!$gateway->verifyWebhookSignature($request)) {
                return response()->json(['error' => 'Invalid signature'], 401);
            }

            // Parse webhook
            $payload = $gateway->parseWebhook($request);

            // Providers redeliver on any non-2xx, on timeout, and sometimes
            // unprompted. Claim the event id so a replay can't re-run fulfilment.
            // insertOrIgnore is atomic on the primary key, so two concurrent
            // deliveries can't both pass. The claim shares a transaction with the
            // handling: if handling throws, the claim rolls back and the provider's
            // retry is processed instead of being dropped as a duplicate.
            $eventKey = $driver.':'.$payload->id;

            $result = DB::transaction(function () use ($eventKey, $payload, $gateway): ?array {
                if (DB::table('payment_webhook_events')->insertOrIgnore(['id' => $eventKey, 'created_at' => now()]) === 0) {
                    return null;
                }

                // Dispatch event for listeners
                event(new PaymentWebhookReceived($payload));

                // Handle webhook
                return $gateway->handleWebhook($payload);
            });

            if ($result === null) {
                return response()->json(['handled' => true, 'duplicate' => true]);
            }

            // Dispatch success event
            event(new WebhookHandled($payload, $result));

            return response()->json($result);
        } catch (InvalidWebhookPayloadException $e) {
            // Authentic but malformed: retrying the same bytes can't help, so 4xx.
            // Log the reason only, never the body (it may carry personal data).
            $id = json_decode($content, true)['id'] ?? null;
            Log::warning('Rejected malformed webhook', [
                'driver' => $driver,
                'event' => is_string($id) ? substr($id, 0, 64) : null,
                'reason' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Invalid webhook payload'], 400);
        } catch (Throwable $e) {
            // Dispatch failure event
            event(new WebhookHandleFailed($driver, $e));

            report($e);

            return response()->json(['error' => 'Webhook processing failed'], 500);
        }
    }
}

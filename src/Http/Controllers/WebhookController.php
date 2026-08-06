<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Http\Controllers;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use LaravelPlus\PaymentGateway\Contracts\SupportsWebhooks;
use LaravelPlus\PaymentGateway\Events\PaymentWebhookReceived;
use LaravelPlus\PaymentGateway\Events\WebhookHandled;
use LaravelPlus\PaymentGateway\Events\WebhookHandleFailed;
use LaravelPlus\PaymentGateway\Facades\Payment;

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

        // Validate request body size (max 1MB)
        $maxSize = 1048576;
        if ((int) $request->header('Content-Length', '0') > $maxSize) {
            return response()->json(['error' => 'Payload too large'], 413);
        }

        // Validate body is valid JSON
        $content = $request->getContent();
        if (empty($content) || json_decode($content, true) === null) {
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
            // unprompted. Claim the event id before dispatching so a replay can't
            // re-run fulfilment. insertOrIgnore is atomic on the primary key, so
            // two concurrent deliveries can't both pass this.
            $eventKey = $driver.':'.$payload->id;

            if (DB::table('payment_webhook_events')->insertOrIgnore(['id' => $eventKey, 'created_at' => now()]) === 0) {
                return response()->json(['handled' => true, 'duplicate' => true]);
            }

            // Dispatch event for listeners
            event(new PaymentWebhookReceived($payload));

            // Handle webhook
            $result = $gateway->handleWebhook($payload);

            // Dispatch success event
            event(new WebhookHandled($payload, $result));

            return response()->json($result);
        } catch (Exception $e) {
            // Dispatch failure event
            event(new WebhookHandleFailed($driver, $e));

            report($e);

            return response()->json(['error' => 'Webhook processing failed'], 500);
        }
    }
}

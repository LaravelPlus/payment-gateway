<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Http\Controllers;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nejcc\PaymentGateway\Contracts\SupportsWebhooks;
use Nejcc\PaymentGateway\Events\PaymentWebhookReceived;
use Nejcc\PaymentGateway\Events\WebhookHandled;
use Nejcc\PaymentGateway\Events\WebhookHandleFailed;
use Nejcc\PaymentGateway\Facades\Payment;

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

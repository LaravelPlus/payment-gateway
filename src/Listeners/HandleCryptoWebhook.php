<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Listeners;

use Illuminate\Support\Facades\Log;
use LaravelPlus\PaymentGateway\Enums\PaymentStatus;
use LaravelPlus\PaymentGateway\Events\PaymentFailed;
use LaravelPlus\PaymentGateway\Events\PaymentSucceeded;
use LaravelPlus\PaymentGateway\Events\PaymentWebhookReceived;
use LaravelPlus\PaymentGateway\Models\Invoice;
use LaravelPlus\PaymentGateway\Models\Transaction;
use LaravelPlus\PaymentGateway\Services\InvoicePdfGenerator;

/**
 * Handle Crypto webhook events and update database accordingly.
 */
final class HandleCryptoWebhook
{
    public function __construct(
        private readonly InvoicePdfGenerator $pdfGenerator,
    ) {}

    /**
     * Handle the event.
     */
    public function handle(PaymentWebhookReceived $event): void
    {
        if ($event->payload->driver !== 'crypto') {
            return;
        }

        $type = $event->payload->type;
        $data = $event->payload->data;

        Log::info('Processing Crypto webhook', ['type' => $type]);

        match ($type) {
            'charge:confirmed', 'charge:completed' => $this->handleChargeCompleted($data),
            'charge:failed' => $this->handleChargeFailed($data),
            'charge:delayed' => $this->handleChargeDelayed($data),
            'charge:pending' => $this->handleChargePending($data),
            'charge:resolved' => $this->handleChargeResolved($data),
            default => Log::debug('Unhandled Crypto webhook type', ['type' => $type]),
        };
    }

    private function handleChargeCompleted(array $data): void
    {
        $chargeId = $data['id'] ?? $data['code'] ?? null;
        if (!$chargeId) {
            return;
        }

        $transaction = Transaction::where('provider_id', $chargeId)
            ->where('driver', 'crypto')
            ->first();

        if ($transaction && $transaction->status !== PaymentStatus::Succeeded->value) {
            $transaction->update([
                'status' => PaymentStatus::Succeeded->value,
                'provider_response' => $data,
            ]);

            $invoice = Invoice::createFromTransaction($transaction);
            $this->pdfGenerator->generate($invoice);

            event(new PaymentSucceeded($transaction->toDto()));
        }
    }

    private function handleChargeFailed(array $data): void
    {
        $chargeId = $data['id'] ?? $data['code'] ?? null;
        if (!$chargeId) {
            return;
        }

        $transaction = Transaction::where('provider_id', $chargeId)
            ->where('driver', 'crypto')
            ->first();

        if ($transaction) {
            $failureMessage = $data['failure_reason'] ?? 'Payment failed';

            $transaction->update([
                'status' => PaymentStatus::Failed->value,
                'failure_message' => $failureMessage,
                'provider_response' => $data,
            ]);

            event(new PaymentFailed($transaction->toDto(), $failureMessage));
        }
    }

    private function handleChargeDelayed(array $data): void
    {
        $chargeId = $data['id'] ?? $data['code'] ?? null;
        if (!$chargeId) {
            return;
        }

        $transaction = Transaction::where('provider_id', $chargeId)
            ->where('driver', 'crypto')
            ->first();

        if ($transaction) {
            $transaction->update([
                'status' => PaymentStatus::Processing->value,
                'provider_response' => $data,
            ]);
        }
    }

    private function handleChargePending(array $data): void
    {
        $chargeId = $data['id'] ?? $data['code'] ?? null;
        if (!$chargeId) {
            return;
        }

        Transaction::where('provider_id', $chargeId)
            ->where('driver', 'crypto')
            ->update([
                'status' => PaymentStatus::Pending->value,
                'provider_response' => $data,
            ]);
    }

    private function handleChargeResolved(array $data): void
    {
        // Resolved means a previously unresolved charge has been resolved
        $this->handleChargeCompleted($data);
    }
}

<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use LaravelPlus\PaymentGateway\Models\Refund;
use NumberFormatter;

final class RefundProcessedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Refund $refund,
    ) {
        // Only send once the webhook transaction has committed; a retry must not resend.
        $this->afterCommit();
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Refund Has Been Processed',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'payment-gateway::emails.refund-processed',
            with: [
                'refund' => $this->refund,
                'user' => $this->refund->user,
                'transaction' => $this->refund->transaction,
                'amount' => $this->formatAmount($this->refund->amount, $this->refund->currency),
            ],
        );
    }

    /**
     * Format amount for display.
     */
    private function formatAmount(int $amount, string $currency): string
    {
        $formatter = new NumberFormatter('en', NumberFormatter::CURRENCY);

        return $formatter->formatCurrency(\LaravelPlus\PaymentGateway\Support\Money::toDecimal($amount, $currency), $currency);
    }
}

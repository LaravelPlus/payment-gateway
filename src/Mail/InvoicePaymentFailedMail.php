<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class InvoicePaymentFailedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly object $user,
        public readonly int $amountDue,
        public readonly string $currency,
        public readonly ?string $invoiceUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your subscription payment failed');
    }

    public function content(): Content
    {
        return new Content(markdown: 'payment-gateway::emails.invoice-payment-failed', with: [
            'amount' => number_format($this->amountDue / 100, 2).' '.mb_strtoupper($this->currency),
        ]);
    }
}

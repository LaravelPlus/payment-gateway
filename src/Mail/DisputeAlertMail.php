<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class DisputeAlertMail extends Mailable
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $dispute
     */
    public function __construct(
        public readonly string $type,
        public readonly array $dispute,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Payment dispute: '.$this->type);
    }

    public function content(): Content
    {
        return new Content(markdown: 'payment-gateway::emails.dispute-alert', with: [
            'amount' => number_format(((int) ($this->dispute['amount'] ?? 0)) / 100, 2).' '.mb_strtoupper((string) ($this->dispute['currency'] ?? '')),
        ]);
    }
}

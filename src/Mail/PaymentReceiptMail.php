<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Nejcc\PaymentGateway\Models\Invoice;
use Nejcc\PaymentGateway\Models\Transaction;
use NumberFormatter;

final class PaymentReceiptMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Transaction $transaction,
        public readonly ?Invoice $invoice = null,
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Payment Receipt - '.$this->transaction->provider_id,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'payment-gateway::emails.payment-receipt',
            with: [
                'transaction' => $this->transaction,
                'invoice' => $this->invoice,
                'user' => $this->transaction->user,
                'amount' => $this->formatAmount($this->transaction->amount, $this->transaction->currency),
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        $attachments = [];

        if ($this->invoice && $this->invoice->hasPdf()) {
            $attachments[] = \Illuminate\Mail\Mailables\Attachment::fromStorage($this->invoice->pdf_path)
                ->as('invoice-'.$this->invoice->number.'.pdf')
                ->withMime('application/pdf');
        }

        return $attachments;
    }

    /**
     * Format amount for display.
     */
    private function formatAmount(int $amount, string $currency): string
    {
        $formatter = new NumberFormatter('en', NumberFormatter::CURRENCY);

        return $formatter->formatCurrency($amount / 100, $currency);
    }
}

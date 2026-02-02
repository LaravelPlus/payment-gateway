<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Nejcc\PaymentGateway\Models\Subscription;
use NumberFormatter;

final class SubscriptionCreatedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Subscription $subscription,
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Welcome to Your New Subscription',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'payment-gateway::emails.subscription-created',
            with: [
                'subscription' => $this->subscription,
                'user' => $this->subscription->user,
                'plan' => $this->subscription->plan,
                'amount' => $this->formatAmount($this->subscription->amount, $this->subscription->currency),
                'nextBillingDate' => $this->subscription->current_period_end?->format('F j, Y'),
                'trialEndsAt' => $this->subscription->trial_end?->format('F j, Y'),
            ],
        );
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

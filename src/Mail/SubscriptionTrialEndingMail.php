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

final class SubscriptionTrialEndingMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Subscription $subscription,
        public readonly int $daysRemaining = 3,
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Trial Ends in '.$this->daysRemaining.' Days',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'payment-gateway::emails.subscription-trial-ending',
            with: [
                'subscription' => $this->subscription,
                'user' => $this->subscription->user,
                'plan' => $this->subscription->plan,
                'daysRemaining' => $this->daysRemaining,
                'trialEndsAt' => $this->subscription->trial_end?->format('F j, Y'),
                'amount' => $this->formatAmount($this->subscription->amount, $this->subscription->currency),
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

<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Tests\Unit\Drivers;

use Illuminate\Http\Request;
use LaravelPlus\PaymentGateway\Drivers\StripeGateway;
use LaravelPlus\PaymentGateway\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class StripeWebhookSignatureTest extends TestCase
{
    private const PAYLOAD = '{"id":"evt_test","object":"event","type":"checkout.session.completed"}';

    private function request(string $secret): Request
    {
        $t = time();
        $sig = 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.self::PAYLOAD, $secret);

        return Request::create('/webhooks/payment/stripe', 'POST', server: ['HTTP_STRIPE_SIGNATURE' => $sig], content: self::PAYLOAD);
    }

    private function gateway(?string $webhookSecret): StripeGateway
    {
        return new StripeGateway(['secret_key' => 'sk_test_x', 'webhook_secret' => $webhookSecret]);
    }

    /** An unset secret must fail closed: with an empty key the HMAC is computable by anyone. */
    #[Test]
    #[DataProvider('missingSecrets')]
    public function rejects_every_webhook_when_the_secret_is_missing(?string $secret): void
    {
        $this->assertFalse($this->gateway($secret)->verifyWebhookSignature($this->request('')));
    }

    public static function missingSecrets(): array
    {
        return ['null' => [null], 'empty string (KEY= in .env)' => ['']];
    }

    #[Test]
    public function accepts_a_correctly_signed_webhook(): void
    {
        $this->assertTrue($this->gateway('whsec_real')->verifyWebhookSignature($this->request('whsec_real')));
    }

    #[Test]
    public function rejects_a_webhook_signed_with_another_secret(): void
    {
        $this->assertFalse($this->gateway('whsec_real')->verifyWebhookSignature($this->request('whsec_other')));
    }
}

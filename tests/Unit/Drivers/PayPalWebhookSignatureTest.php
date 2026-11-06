<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Tests\Unit\Drivers;

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use LaravelPlus\PaymentGateway\Drivers\PayPalGateway;
use LaravelPlus\PaymentGateway\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * gh-11: PayPal signs its original bytes, so the verify call must carry them
 * unchanged. Decoding and re-encoding turns "10.10" into 10.1, "\/" into "/"
 * and {} into [], and real events then fail verification.
 */
class PayPalWebhookSignatureTest extends TestCase
{
    private const RAW = '{"id":"WH-1","event_type":"PAYMENT.CAPTURE.COMPLETED","resource":{"amount":{"value":"10.10","total":10.10},"links":[{"href":"https:\/\/api.example\/v2\/x"}],"extra":{}},"summary":"café"}';

    private const HEADERS = [
        'HTTP_PAYPAL_AUTH_ALGO' => 'SHA256withRSA',
        'HTTP_PAYPAL_CERT_URL' => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-1',
        'HTTP_PAYPAL_TRANSMISSION_ID' => 'tx-1',
        'HTTP_PAYPAL_TRANSMISSION_SIG' => 'sig',
        'HTTP_PAYPAL_TRANSMISSION_TIME' => '2026-01-01T00:00:00Z',
    ];

    private function gateway(): PayPalGateway
    {
        return new PayPalGateway(['client_id' => 'x', 'client_secret' => 'y', 'mode' => 'sandbox', 'webhook_id' => 'WEBHOOK-1']);
    }

    private function request(array $headers = self::HEADERS): Request
    {
        return Request::create('/webhooks/payment/paypal', 'POST', server: $headers + ['CONTENT_TYPE' => 'application/json'], content: self::RAW);
    }

    #[Test]
    public function the_verify_call_carries_the_raw_event_bytes(): void
    {
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 't']),
            '*/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
        ]);

        $this->assertTrue($this->gateway()->verifyWebhookSignature($this->request()));

        Http::assertSent(function (HttpRequest $r): bool {
            if (!str_ends_with($r->url(), '/verify-webhook-signature')) {
                return false;
            }
            $body = $r->body();
            $this->assertStringEndsWith(',"webhook_event":'.self::RAW.'}', $body);
            $this->assertSame('WEBHOOK-1', json_decode($body, true)['webhook_id']);
            $this->assertSame('tx-1', json_decode($body, true)['transmission_id']);

            return true;
        });
    }

    #[Test]
    public function a_failed_verification_is_rejected(): void
    {
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 't']),
            '*' => Http::response(['verification_status' => 'FAILURE']),
        ]);

        $this->assertFalse($this->gateway()->verifyWebhookSignature($this->request()));
    }

    #[Test]
    public function missing_signature_headers_are_rejected_without_calling_paypal(): void
    {
        Http::fake();

        $headers = self::HEADERS;
        unset($headers['HTTP_PAYPAL_TRANSMISSION_SIG']);

        $this->assertFalse($this->gateway()->verifyWebhookSignature($this->request($headers)));
        Http::assertNothingSent();
    }
}

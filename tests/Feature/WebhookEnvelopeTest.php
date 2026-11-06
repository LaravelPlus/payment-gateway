<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use LaravelPlus\PaymentGateway\Drivers\CryptoGateway;
use LaravelPlus\PaymentGateway\Drivers\PayPalGateway;
use LaravelPlus\PaymentGateway\Events\PaymentWebhookReceived;
use LaravelPlus\PaymentGateway\Exceptions\InvalidWebhookPayloadException;
use LaravelPlus\PaymentGateway\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * gh-29: the body is bounded by its real size, and a signed but malformed
 * envelope is a permanent 4xx that never reaches the event claim or listeners.
 */
class WebhookEnvelopeTest extends TestCase
{
    private const SECRET = 'whsec_test_envelope';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('payment-gateway.drivers.stripe.secret_key', 'sk_test_x');
        $app['config']->set('payment-gateway.drivers.stripe.webhook_secret', self::SECRET);
    }

    private function send(string $body, ?string $secret = self::SECRET, array $server = []): TestResponse
    {
        if ($secret !== null) {
            $t = time();
            $server['HTTP_STRIPE_SIGNATURE'] = 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$body, $secret);
        }

        return $this->call('POST', '/webhooks/payment/stripe', server: $server + ['CONTENT_TYPE' => 'application/json'], content: $body);
    }

    private static function event(array $overrides = []): string
    {
        return json_encode(array_merge([
            'id' => 'evt_envelope_1',
            'object' => 'event',
            'type' => 'test.event',
            'created' => 1_700_000_000,
            'data' => ['object' => ['id' => 'obj_1']],
        ], $overrides));
    }

    #[Test]
    #[DataProvider('contentLengths')]
    public function rejects_an_oversized_body_whatever_content_length_claims(array $server): void
    {
        $body = self::event(['data' => ['object' => ['pad' => str_repeat('x', 1_048_577)]]]);

        $this->send($body, server: $server)->assertStatus(413);
    }

    public static function contentLengths(): array
    {
        return ['missing' => [[]], 'spoofed small' => [['CONTENT_LENGTH' => '10']]];
    }

    #[Test]
    #[DataProvider('nonObjectJson')]
    public function rejects_json_that_is_not_an_object(string $body): void
    {
        $this->send($body)->assertStatus(400)->assertJson(['error' => 'Invalid JSON payload']);
    }

    public static function nonObjectJson(): array
    {
        return ['array' => ['[1,2]'], 'empty array' => ['[]'], 'number' => ['1'], 'string' => ['"evt"'], 'null' => ['null'], 'broken' => ['{"id":']];
    }

    #[Test]
    #[DataProvider('badEnvelopes')]
    public function rejects_a_signed_but_malformed_envelope_before_claiming_it(array $overrides): void
    {
        Event::fake([PaymentWebhookReceived::class]);

        $body = json_decode(self::event(), true);
        foreach ($overrides as $key => $value) {
            if ($value === null) {
                unset($body[$key]);
            } else {
                $body[$key] = $value;
            }
        }

        $this->send(json_encode((object) $body))->assertStatus(400)->assertJson(['error' => 'Invalid webhook payload']);

        Event::assertNotDispatched(PaymentWebhookReceived::class);
        $this->assertSame(0, DB::table('payment_webhook_events')->count());
    }

    public static function badEnvelopes(): array
    {
        return [
            'missing id' => [['id' => null]],
            'empty id' => [['id' => '']],
            'numeric id' => [['id' => 42]],
            'missing type' => [['type' => null]],
            'missing created' => [['created' => null]],
            'string created' => [['created' => 'yesterday']],
            'missing data' => [['data' => null]],
            'data without object' => [['data' => ['x' => 1]]],
            'data object is scalar' => [['data' => ['object' => 'obj_1']]],
        ];
    }

    #[Test]
    public function checks_the_signature_before_the_envelope(): void
    {
        $this->send('{"not":"an event"}', secret: null)->assertStatus(401);
        $this->send(self::event(), secret: 'whsec_wrong')->assertStatus(401);
    }

    #[Test]
    public function a_redelivered_valid_event_is_handled_once(): void
    {
        $this->send(self::event())->assertOk()->assertJson(['handled' => true]);
        $this->send(self::event())->assertOk()->assertJson(['duplicate' => true]);
    }

    #[Test]
    public function a_transient_handler_failure_is_a_5xx_and_the_retry_is_processed(): void
    {
        $calls = 0;
        Event::listen(PaymentWebhookReceived::class, function () use (&$calls): void {
            if (++$calls === 1) {
                throw new RuntimeException('database went away');
            }
        });

        $this->send(self::event())->assertStatus(500);
        $this->send(self::event())->assertOk()->assertJsonMissing(['duplicate' => true]);
        $this->assertSame(2, $calls);
    }

    #[Test]
    public function paypal_rejects_a_malformed_envelope(): void
    {
        $gateway = new PayPalGateway([]);
        $valid = ['id' => 'WH-1', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'create_time' => '2026-01-01T00:00:00Z', 'resource' => ['id' => 'CAP-1']];

        $this->assertSame('WH-1', $gateway->parseWebhook($this->request($valid))->id);

        foreach ([['id' => null], ['event_type' => null], ['resource' => null], ['resource' => 'CAP-1'], ['create_time' => 'not a date']] as $override) {
            $body = array_filter(array_merge($valid, $override), fn ($v) => $v !== null);
            try {
                $gateway->parseWebhook($this->request($body));
                $this->fail('accepted '.json_encode($override));
            } catch (InvalidWebhookPayloadException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function crypto_rejects_a_non_array_data_or_type(): void
    {
        $gateway = new CryptoGateway([]);

        $this->assertSame('charge:confirmed', $gateway->parseWebhook($this->request(['id' => 'e1', 'event' => ['type' => 'charge:confirmed', 'data' => []]]))->type);

        $this->expectException(InvalidWebhookPayloadException::class);
        $gateway->parseWebhook($this->request(['id' => 'e1', 'event' => ['type' => ['x'], 'data' => 'oops']]));
    }

    /** gh-3: an event without an id gets a stable one, so a redelivery is caught as a duplicate. */
    #[Test]
    public function crypto_events_without_an_id_get_a_stable_id_from_their_bytes(): void
    {
        $gateway = new CryptoGateway([]);
        $event = ['event' => ['type' => 'charge:confirmed', 'data' => ['code' => 'C1']]];

        $first = $gateway->parseWebhook($this->request($event))->id;

        $this->assertSame($first, $gateway->parseWebhook($this->request($event))->id);
        $this->assertSame('sha256:'.hash('sha256', json_encode($event)), $first);
        $this->assertNotSame($first, $gateway->parseWebhook($this->request(['event' => ['type' => 'charge:confirmed', 'data' => ['code' => 'C2']]]))->id);
        $this->assertSame('evt_9', $gateway->parseWebhook($this->request(['event' => ['id' => 'evt_9', 'type' => 'x', 'data' => []]]))->id);
        $this->assertSame('42', $gateway->parseWebhook($this->request(['id' => 42, 'type' => 'x', 'data' => []]))->id);

        $this->expectException(InvalidWebhookPayloadException::class);
        $gateway->parseWebhook($this->request(['id' => ['nested'], 'type' => 'x', 'data' => []]));
    }

    private function request(array $body): Request
    {
        return Request::create('/', 'POST', content: json_encode($body));
    }
}

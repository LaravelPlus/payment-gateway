<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Tests\Unit\DTOs;

use DateTimeInterface;
use LaravelPlus\PaymentGateway\DTOs\WebhookPayload;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WebhookPayloadTest extends TestCase
{
    #[Test]
    public function it_creates_from_array_and_round_trips(): void
    {
        $data = [
            'id' => 'evt_123',
            'type' => 'payment_intent.succeeded',
            'driver' => 'stripe',
            'data' => [
                'object' => [
                    'id' => 'pi_456',
                    'amount' => 2500,
                    'currency' => 'usd',
                ],
            ],
            'created_at' => '2025-06-15T10:30:00+00:00',
            'raw' => ['original' => 'payload'],
        ];

        $payload = WebhookPayload::fromArray($data);

        $this->assertSame('evt_123', $payload->id);
        $this->assertSame('payment_intent.succeeded', $payload->type);
        $this->assertSame('stripe', $payload->driver);
        $this->assertSame('pi_456', $payload->data['object']['id']);
        $this->assertSame(2500, $payload->data['object']['amount']);
        $this->assertInstanceOf(DateTimeInterface::class, $payload->createdAt);
        $this->assertSame(['original' => 'payload'], $payload->raw);

        $array = $payload->toArray();
        $roundTripped = WebhookPayload::fromArray($array);

        $this->assertSame($payload->id, $roundTripped->id);
        $this->assertSame($payload->type, $roundTripped->type);
        $this->assertSame($payload->driver, $roundTripped->driver);
        $this->assertSame($payload->data, $roundTripped->data);
    }

    #[Test]
    public function to_array_includes_all_fields(): void
    {
        $payload = new WebhookPayload(
            id: 'evt_1',
            type: 'charge.refunded',
            driver: 'stripe',
            data: ['refund_id' => 'ref_1'],
        );

        $array = $payload->toArray();

        $this->assertSame('evt_1', $array['id']);
        $this->assertSame('charge.refunded', $array['type']);
        $this->assertSame('stripe', $array['driver']);
        $this->assertSame(['refund_id' => 'ref_1'], $array['data']);
        $this->assertNull($array['created_at']);
    }

    #[Test]
    public function get_returns_value_from_data_using_dot_notation(): void
    {
        $payload = new WebhookPayload(
            id: 'evt_1',
            type: 'test',
            driver: 'stripe',
            data: [
                'object' => [
                    'id' => 'pi_123',
                    'amount' => 5000,
                    'nested' => [
                        'deep' => 'value',
                    ],
                ],
            ],
        );

        $this->assertSame('pi_123', $payload->get('object.id'));
        $this->assertSame(5000, $payload->get('object.amount'));
        $this->assertSame('value', $payload->get('object.nested.deep'));
    }

    #[Test]
    public function get_returns_default_when_key_not_found(): void
    {
        $payload = new WebhookPayload(
            id: 'evt_1',
            type: 'test',
            driver: 'stripe',
            data: ['key' => 'value'],
        );

        $this->assertNull($payload->get('nonexistent'));
        $this->assertSame('fallback', $payload->get('nonexistent', 'fallback'));
    }

    #[Test]
    public function get_returns_top_level_value(): void
    {
        $payload = new WebhookPayload(
            id: 'evt_1',
            type: 'test',
            driver: 'stripe',
            data: ['status' => 'completed'],
        );

        $this->assertSame('completed', $payload->get('status'));
    }

    #[Test]
    public function is_type_returns_true_for_matching_type(): void
    {
        $payload = new WebhookPayload(
            id: 'evt_1',
            type: 'invoice.paid',
            driver: 'stripe',
            data: [],
        );

        $this->assertTrue($payload->isType('invoice.paid'));
    }

    #[Test]
    public function is_type_returns_false_for_non_matching_type(): void
    {
        $payload = new WebhookPayload(
            id: 'evt_1',
            type: 'invoice.paid',
            driver: 'stripe',
            data: [],
        );

        $this->assertFalse($payload->isType('invoice.failed'));
    }

    #[Test]
    public function is_any_type_returns_true_when_type_is_in_array(): void
    {
        $payload = new WebhookPayload(
            id: 'evt_1',
            type: 'charge.succeeded',
            driver: 'stripe',
            data: [],
        );

        $this->assertTrue($payload->isAnyType([
            'charge.succeeded',
            'charge.failed',
            'charge.refunded',
        ]));
    }

    #[Test]
    public function is_any_type_returns_false_when_type_not_in_array(): void
    {
        $payload = new WebhookPayload(
            id: 'evt_1',
            type: 'invoice.paid',
            driver: 'stripe',
            data: [],
        );

        $this->assertFalse($payload->isAnyType([
            'charge.succeeded',
            'charge.failed',
        ]));
    }

    #[Test]
    public function is_any_type_returns_false_for_empty_array(): void
    {
        $payload = new WebhookPayload(
            id: 'evt_1',
            type: 'invoice.paid',
            driver: 'stripe',
            data: [],
        );

        $this->assertFalse($payload->isAnyType([]));
    }

    #[Test]
    public function from_array_handles_missing_optional_fields(): void
    {
        $payload = WebhookPayload::fromArray([
            'id' => 'evt_1',
            'type' => 'test.event',
            'driver' => 'stripe',
        ]);

        $this->assertSame([], $payload->data);
        $this->assertNull($payload->createdAt);
        $this->assertSame([], $payload->raw);
    }

    #[Test]
    public function to_array_formats_created_at_as_iso8601(): void
    {
        $payload = WebhookPayload::fromArray([
            'id' => 'evt_1',
            'type' => 'test',
            'driver' => 'stripe',
            'data' => [],
            'created_at' => '2025-03-15T14:30:00+00:00',
        ]);

        $array = $payload->toArray();

        $this->assertNotNull($array['created_at']);
        $this->assertStringContainsString('2025-03-15', $array['created_at']);
    }
}

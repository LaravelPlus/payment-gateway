<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Tests\Unit\Listeners;

use Carbon\Carbon;
use LaravelPlus\PaymentGateway\Listeners\HandleStripeWebhook;
use LaravelPlus\PaymentGateway\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

final class StripeSubscriptionPeriodTest extends TestCase
{
    private function period(array $subscription): ?Carbon
    {
        $listener = new ReflectionClass(HandleStripeWebhook::class);

        return $listener->getMethod('periodTimestamp')
            ->invoke($listener->newInstanceWithoutConstructor(), $subscription, 'current_period_end');
    }

    #[Test]
    public function reads_the_period_from_the_subscription_item_on_newer_api_versions(): void
    {
        $period = $this->period(['items' => ['data' => [['current_period_end' => 1800000000]]]]);

        $this->assertSame(1800000000, $period?->getTimestamp());
    }

    #[Test]
    public function still_reads_the_top_level_period_on_older_api_versions(): void
    {
        $this->assertSame(1700000000, $this->period(['current_period_end' => 1700000000])?->getTimestamp());
    }

    #[Test]
    public function returns_null_when_neither_is_present(): void
    {
        $this->assertNull($this->period(['items' => ['data' => [[]]]]));
    }
}

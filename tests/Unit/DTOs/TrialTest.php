<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Tests\Unit\DTOs;

use DateTimeImmutable;
use LaravelPlus\PaymentGateway\DTOs\Trial;
use LaravelPlus\PaymentGateway\Exceptions\PaymentException;
use LaravelPlus\PaymentGateway\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class TrialTest extends TestCase
{
    #[Test]
    #[DataProvider('lengths')]
    public function any_valid_length_maps_to_trial_period_days(int $days): void
    {
        $this->assertSame(['trial_period_days' => $days], Trial::fromOptions(['trial_days' => $days])->toStripe());
    }

    public static function lengths(): array
    {
        return ['1' => [1], '7' => [7], '14' => [14], '30' => [30], '40' => [40], 'max' => [730]];
    }

    #[Test]
    #[DataProvider('invalid')]
    public function malformed_lengths_fail_before_any_request(mixed $days): void
    {
        $this->expectException(PaymentException::class);
        Trial::fromOptions(['trial_days' => $days]);
    }

    public static function invalid(): array
    {
        return [
            'negative' => [-1], 'decimal' => [1.5], 'decimal string' => ['7.5'], 'text' => ['abc'],
            'bool' => [true], 'over provider max' => [731], 'very large' => [PHP_INT_MAX],
        ];
    }

    #[Test]
    public function no_trial_unless_asked_for(): void
    {
        $this->assertNull(Trial::fromOptions([]));
        $this->assertNull(Trial::fromOptions(['trial_days' => null]));
        // 0 = "plan has no trial", what existing callers pass for $plan->trial_days.
        $this->assertNull(Trial::fromOptions(['trial_days' => 0]));
        $this->assertNull(Trial::fromOptions(['trial_period_days' => 0]));
    }

    #[Test]
    public function legacy_checkout_name_still_works_and_numeric_strings_are_accepted(): void
    {
        $this->assertSame(14, Trial::fromOptions(['trial_period_days' => 14])->days);
        $this->assertSame(14, Trial::fromOptions(['trial_days' => '14', 'trial_period_days' => 14])->days);
    }

    #[Test]
    public function conflicting_inputs_are_rejected(): void
    {
        foreach ([
            ['trial_days' => 7, 'trial_period_days' => 14],
            ['trial_days' => 7, 'trial_end' => time() + 30 * 86400],
        ] as $options) {
            try {
                Trial::fromOptions($options);
                $this->fail('accepted '.json_encode($options));
            } catch (PaymentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function explicit_end_date_is_passed_as_trial_end(): void
    {
        $end = new DateTimeImmutable('+10 days');
        $this->assertSame(['trial_end' => $end->getTimestamp()], Trial::fromOptions(['trial_end' => $end])->toStripe());
    }

    #[Test]
    public function end_date_must_be_inside_the_window(): void
    {
        foreach ([[time() - 60, 0], [time() + 3600, 48 * 3600], [time() + 731 * 86400, 0], ['tomorrow', 0]] as [$end, $lead]) {
            try {
                Trial::fromOptions(['trial_end' => $end], $lead);
                $this->fail('accepted '.var_export($end, true));
            } catch (PaymentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    #[DataProvider('behaviors')]
    public function end_behavior_maps_to_trial_settings(string $behavior): void
    {
        $this->assertSame(
            ['trial_period_days' => 7, 'trial_settings' => ['end_behavior' => ['missing_payment_method' => $behavior]]],
            Trial::fromOptions(['trial_days' => 7, 'trial_end_behavior' => $behavior])->toStripe(),
        );
    }

    public static function behaviors(): array
    {
        return ['cancel' => ['cancel'], 'pause' => ['pause'], 'create_invoice' => ['create_invoice']];
    }

    #[Test]
    public function unknown_end_behavior_is_rejected(): void
    {
        $this->expectException(PaymentException::class);
        Trial::fromOptions(['trial_days' => 7, 'trial_end_behavior' => 'refund']);
    }

    #[Test]
    public function config_supplies_the_default_end_behavior_and_maximum(): void
    {
        config(['payment-gateway.subscriptions.trial_end_behavior' => 'cancel', 'payment-gateway.subscriptions.max_trial_days' => 30]);

        $this->assertSame('cancel', Trial::fromOptions(['trial_days' => 30])->endBehavior);

        $this->expectException(PaymentException::class);
        Trial::fromOptions(['trial_days' => 40]);
    }
}

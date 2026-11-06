<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Tests\Unit\Support;

use LaravelPlus\PaymentGateway\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    /** @return array<string, array{string, string, int}> */
    public static function minorProvider(): array
    {
        return [
            '0.29' => ['0.29', 'USD', 29],
            '19.99' => ['19.99', 'USD', 1999],
            '4.35' => ['4.35', 'EUR', 435],
            '1.15' => ['1.15', 'USD', 115],
            'JPY whole' => ['1000', 'JPY', 1000],
        ];
    }

    #[DataProvider('minorProvider')]
    public function test_to_minor_rounds_instead_of_truncating(string $value, string $currency, int $expected): void
    {
        $this->assertSame($expected, Money::toMinor($value, $currency));
    }

    public function test_zero_decimal_currencies_are_not_divided_by_100(): void
    {
        $this->assertSame('1000', Money::toDecimalString(1000, 'JPY'));
        $this->assertSame(1000.0, Money::toDecimal(1000, 'jpy'));
        $this->assertSame('10.50', Money::toDecimalString(1050, 'USD'));
    }

    public function test_three_decimal_currencies_use_thousandths(): void
    {
        $this->assertSame(10500, Money::toMinor('10.5', 'KWD'));
        $this->assertSame('10.500', Money::toDecimalString(10500, 'kwd'));
        $this->assertSame(3, Money::decimals('BHD'));
    }
}

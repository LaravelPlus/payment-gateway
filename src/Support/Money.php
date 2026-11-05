<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Support;

/**
 * Minor-unit helpers. Amounts are stored in the currency's smallest unit as
 * Stripe defines it (USD cents, JPY yen), so zero-decimal currencies use 1.
 */
final class Money
{
    /** @var list<string> */
    private const ZERO_DECIMAL = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    public static function decimals(string $currency): int
    {
        return in_array(mb_strtoupper($currency), self::ZERO_DECIMAL, true) ? 0 : 2;
    }

    /**
     * Provider decimal ("19.99") to minor units. Rounds, never truncates.
     */
    public static function toMinor(string|int|float|null $decimal, string $currency): int
    {
        return (int) round((float) $decimal * 10 ** self::decimals($currency));
    }

    public static function toDecimal(int $minor, string $currency): float
    {
        return $minor / 10 ** self::decimals($currency);
    }

    /**
     * Minor units to a plain decimal string for API requests ("10.50", JPY "1000").
     */
    public static function toDecimalString(int $minor, string $currency): string
    {
        return number_format(self::toDecimal($minor, $currency), self::decimals($currency), '.', '');
    }
}

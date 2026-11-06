<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Tests\Unit\Drivers;

use LaravelPlus\PaymentGateway\Contracts\SupportsCheckoutSessions;
use LaravelPlus\PaymentGateway\Contracts\SupportsCustomers;
use LaravelPlus\PaymentGateway\Contracts\SupportsRefunds;
use LaravelPlus\PaymentGateway\Contracts\SupportsSubscriptions;
use LaravelPlus\PaymentGateway\Contracts\SupportsWebhooks;
use LaravelPlus\PaymentGateway\Drivers\BankTransferGateway;
use LaravelPlus\PaymentGateway\Drivers\CashOnDeliveryGateway;
use LaravelPlus\PaymentGateway\Drivers\CryptoGateway;
use LaravelPlus\PaymentGateway\Drivers\PayPalGateway;
use LaravelPlus\PaymentGateway\Drivers\StripeGateway;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * gh-30: docs/capabilities.md is the public promise of what each driver does.
 * It is checked against the interfaces each driver implements, so it can't drift.
 */
class CapabilityMatrixTest extends TestCase
{
    private const DRIVERS = [
        'Stripe' => StripeGateway::class,
        'PayPal' => PayPalGateway::class,
        'Crypto' => CryptoGateway::class,
        'Bank transfer' => BankTransferGateway::class,
        'Cash on delivery' => CashOnDeliveryGateway::class,
    ];

    private const CAPABILITIES = [
        'Hosted checkout' => SupportsCheckoutSessions::class,
        'Customers' => SupportsCustomers::class,
        'Refunds' => SupportsRefunds::class,
        'Subscriptions' => SupportsSubscriptions::class,
        'Webhooks' => SupportsWebhooks::class,
    ];

    /**
     * @return array<string, array<string, bool>> driver => capability => documented as supported
     */
    private function documented(): array
    {
        $doc = (string) file_get_contents(__DIR__.'/../../../docs/capabilities.md');
        // Only the first table (the matrix); the doc has others below it.
        preg_match('/^\| Capability .*?(?=\n\n)/ms', $doc, $table);
        preg_match_all('/^\|(.+)\|\s*$/m', $table[0] ?? '', $rows);

        $header = null;
        $matrix = [];
        foreach ($rows[1] as $row) {
            $cells = array_map('trim', explode('|', $row));
            if ($header === null) {
                $header = $cells;

                continue;
            }
            if (str_starts_with($cells[0], '-')) {
                continue;
            }
            foreach (array_slice($cells, 1) as $i => $cell) {
                $matrix[$header[$i + 1]][$cells[0]] = $cell === 'yes';
            }
        }

        return $matrix;
    }

    #[Test]
    public function the_documented_matrix_matches_what_each_driver_implements(): void
    {
        $documented = $this->documented();

        $this->assertSame(array_keys(self::DRIVERS), array_keys($documented), 'docs/capabilities.md must list every driver, in order');

        foreach (self::DRIVERS as $name => $class) {
            foreach (self::CAPABILITIES as $capability => $contract) {
                $this->assertArrayHasKey($capability, $documented[$name], "{$capability} row missing");
                $this->assertSame(
                    is_subclass_of($class, $contract),
                    $documented[$name][$capability],
                    "docs/capabilities.md says {$name} ".($documented[$name][$capability] ? 'supports' : 'lacks')." {$capability}",
                );
            }
        }
    }
}

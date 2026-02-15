<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Tests\Unit\DTOs;

use LaravelPlus\PaymentGateway\DTOs\Address;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AddressTest extends TestCase
{
    #[Test]
    public function it_creates_from_array_and_round_trips(): void
    {
        $data = [
            'line1' => '123 Main St',
            'line2' => 'Apt 4',
            'city' => 'Springfield',
            'state' => 'IL',
            'postal_code' => '62704',
            'country' => 'US',
        ];

        $address = Address::fromArray($data);

        $this->assertSame('123 Main St', $address->line1);
        $this->assertSame('Apt 4', $address->line2);
        $this->assertSame('Springfield', $address->city);
        $this->assertSame('IL', $address->state);
        $this->assertSame('62704', $address->postalCode);
        $this->assertSame('US', $address->country);

        $roundTripped = Address::fromArray($address->toArray());

        $this->assertSame($address->line1, $roundTripped->line1);
        $this->assertSame($address->line2, $roundTripped->line2);
        $this->assertSame($address->city, $roundTripped->city);
        $this->assertSame($address->state, $roundTripped->state);
        $this->assertSame($address->postalCode, $roundTripped->postalCode);
        $this->assertSame($address->country, $roundTripped->country);
    }

    #[Test]
    public function to_array_filters_null_values(): void
    {
        $address = new Address(
            line1: '123 Main St',
            city: 'Springfield',
            country: 'US',
        );

        $array = $address->toArray();

        $this->assertArrayHasKey('line1', $array);
        $this->assertArrayHasKey('city', $array);
        $this->assertArrayHasKey('country', $array);
        $this->assertArrayNotHasKey('line2', $array);
        $this->assertArrayNotHasKey('state', $array);
        $this->assertArrayNotHasKey('postal_code', $array);
    }

    #[Test]
    public function is_complete_returns_true_when_all_required_fields_are_set(): void
    {
        $address = new Address(
            line1: '123 Main St',
            city: 'Springfield',
            postalCode: '62704',
            country: 'US',
        );

        $this->assertTrue($address->isComplete());
    }

    #[Test]
    public function is_complete_returns_false_when_required_fields_are_missing(): void
    {
        $missingLine1 = new Address(city: 'Springfield', postalCode: '62704', country: 'US');
        $this->assertFalse($missingLine1->isComplete());

        $missingCity = new Address(line1: '123 Main St', postalCode: '62704', country: 'US');
        $this->assertFalse($missingCity->isComplete());

        $missingPostalCode = new Address(line1: '123 Main St', city: 'Springfield', country: 'US');
        $this->assertFalse($missingPostalCode->isComplete());

        $missingCountry = new Address(line1: '123 Main St', city: 'Springfield', postalCode: '62704');
        $this->assertFalse($missingCountry->isComplete());

        $allNull = new Address();
        $this->assertFalse($allNull->isComplete());
    }

    #[Test]
    public function to_single_line_joins_non_null_parts(): void
    {
        $address = new Address(
            line1: '123 Main St',
            line2: 'Apt 4',
            city: 'Springfield',
            state: 'IL',
            postalCode: '62704',
            country: 'US',
        );

        $this->assertSame('123 Main St, Apt 4, Springfield, IL, 62704, US', $address->toSingleLine());
    }

    #[Test]
    public function to_single_line_skips_null_parts(): void
    {
        $address = new Address(
            line1: '123 Main St',
            city: 'Springfield',
            country: 'US',
        );

        $this->assertSame('123 Main St, Springfield, US', $address->toSingleLine());
    }

    #[Test]
    public function to_single_line_returns_empty_string_for_empty_address(): void
    {
        $address = new Address();

        $this->assertSame('', $address->toSingleLine());
    }

    #[Test]
    public function it_handles_partial_data(): void
    {
        $address = Address::fromArray(['city' => 'Portland']);

        $this->assertNull($address->line1);
        $this->assertNull($address->line2);
        $this->assertSame('Portland', $address->city);
        $this->assertNull($address->state);
        $this->assertNull($address->postalCode);
        $this->assertNull($address->country);
    }

    #[Test]
    public function it_supports_address_line1_alternate_key(): void
    {
        $address = Address::fromArray([
            'address_line1' => '456 Oak Ave',
            'address_line2' => 'Suite 100',
        ]);

        $this->assertSame('456 Oak Ave', $address->line1);
        $this->assertSame('Suite 100', $address->line2);
    }

    #[Test]
    public function it_supports_zip_alternate_key(): void
    {
        $address = Address::fromArray(['zip' => '90210']);

        $this->assertSame('90210', $address->postalCode);
    }

    #[Test]
    public function it_supports_postcode_alternate_key(): void
    {
        $address = Address::fromArray(['postcode' => 'SW1A 1AA']);

        $this->assertSame('SW1A 1AA', $address->postalCode);
    }

    #[Test]
    public function it_supports_region_alternate_key(): void
    {
        $address = Address::fromArray(['region' => 'California']);

        $this->assertSame('California', $address->state);
    }

    #[Test]
    public function it_supports_province_alternate_key(): void
    {
        $address = Address::fromArray(['province' => 'Ontario']);

        $this->assertSame('Ontario', $address->state);
    }

    #[Test]
    public function it_supports_country_code_alternate_key(): void
    {
        $address = Address::fromArray(['country_code' => 'GB']);

        $this->assertSame('GB', $address->country);
    }

    #[Test]
    public function primary_keys_take_precedence_over_alternate_keys(): void
    {
        $address = Address::fromArray([
            'line1' => 'Primary',
            'address_line1' => 'Alternate',
            'postal_code' => '11111',
            'zip' => '22222',
            'state' => 'IL',
            'region' => 'Midwest',
            'country' => 'US',
            'country_code' => 'GB',
        ]);

        $this->assertSame('Primary', $address->line1);
        $this->assertSame('11111', $address->postalCode);
        $this->assertSame('IL', $address->state);
        $this->assertSame('US', $address->country);
    }
}

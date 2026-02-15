<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Tests\Unit\DTOs;

use LaravelPlus\PaymentGateway\DTOs\Address;
use LaravelPlus\PaymentGateway\DTOs\Company;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CompanyTest extends TestCase
{
    #[Test]
    public function it_creates_from_array_and_round_trips(): void
    {
        $data = [
            'name' => 'Acme Corp',
            'registration_number' => 'REG-123',
            'vat_number' => 'VAT-456',
            'tax_id' => 'TAX-789',
            'address' => [
                'line1' => '100 Business Blvd',
                'city' => 'Commerce City',
                'postal_code' => '80022',
                'country' => 'US',
            ],
            'phone' => '+1-555-0100',
            'email' => 'billing@acme.com',
            'website' => 'https://acme.com',
        ];

        $company = Company::fromArray($data);

        $this->assertSame('Acme Corp', $company->name);
        $this->assertSame('REG-123', $company->registrationNumber);
        $this->assertSame('VAT-456', $company->vatNumber);
        $this->assertSame('TAX-789', $company->taxId);
        $this->assertInstanceOf(Address::class, $company->address);
        $this->assertSame('100 Business Blvd', $company->address->line1);
        $this->assertSame('+1-555-0100', $company->phone);
        $this->assertSame('billing@acme.com', $company->email);
        $this->assertSame('https://acme.com', $company->website);

        $roundTripped = Company::fromArray($company->toArray());

        $this->assertSame($company->name, $roundTripped->name);
        $this->assertSame($company->registrationNumber, $roundTripped->registrationNumber);
        $this->assertSame($company->vatNumber, $roundTripped->vatNumber);
        $this->assertSame($company->taxId, $roundTripped->taxId);
        $this->assertSame($company->phone, $roundTripped->phone);
        $this->assertSame($company->email, $roundTripped->email);
        $this->assertSame($company->website, $roundTripped->website);
    }

    #[Test]
    public function to_array_filters_null_values(): void
    {
        $company = new Company(name: 'Acme Corp');

        $array = $company->toArray();

        $this->assertArrayHasKey('name', $array);
        $this->assertArrayNotHasKey('registration_number', $array);
        $this->assertArrayNotHasKey('vat_number', $array);
        $this->assertArrayNotHasKey('tax_id', $array);
        $this->assertArrayNotHasKey('address', $array);
        $this->assertArrayNotHasKey('phone', $array);
        $this->assertArrayNotHasKey('email', $array);
        $this->assertArrayNotHasKey('website', $array);
    }

    #[Test]
    public function has_vat_number_returns_true_when_set(): void
    {
        $company = new Company(vatNumber: 'DE123456789');

        $this->assertTrue($company->hasVatNumber());
    }

    #[Test]
    public function has_vat_number_returns_false_when_null(): void
    {
        $company = new Company();

        $this->assertFalse($company->hasVatNumber());
    }

    #[Test]
    public function has_vat_number_returns_false_when_empty_string(): void
    {
        $company = new Company(vatNumber: '');

        $this->assertFalse($company->hasVatNumber());
    }

    #[Test]
    public function is_complete_for_invoicing_with_name_and_complete_address(): void
    {
        $company = new Company(
            name: 'Acme Corp',
            address: new Address(
                line1: '100 Business Blvd',
                city: 'Commerce City',
                postalCode: '80022',
                country: 'US',
            ),
        );

        $this->assertTrue($company->isCompleteForInvoicing());
    }

    #[Test]
    public function is_complete_for_invoicing_returns_false_without_name(): void
    {
        $company = new Company(
            address: new Address(
                line1: '100 Business Blvd',
                city: 'Commerce City',
                postalCode: '80022',
                country: 'US',
            ),
        );

        $this->assertFalse($company->isCompleteForInvoicing());
    }

    #[Test]
    public function is_complete_for_invoicing_returns_false_without_address(): void
    {
        $company = new Company(name: 'Acme Corp');

        $this->assertFalse($company->isCompleteForInvoicing());
    }

    #[Test]
    public function is_complete_for_invoicing_returns_false_with_incomplete_address(): void
    {
        $company = new Company(
            name: 'Acme Corp',
            address: new Address(line1: '100 Business Blvd'),
        );

        $this->assertFalse($company->isCompleteForInvoicing());
    }

    #[Test]
    public function it_supports_company_number_alternate_key(): void
    {
        $company = Company::fromArray(['company_number' => 'CN-999']);

        $this->assertSame('CN-999', $company->registrationNumber);
    }

    #[Test]
    public function it_supports_vat_id_alternate_key(): void
    {
        $company = Company::fromArray(['vat_id' => 'VATID-111']);

        $this->assertSame('VATID-111', $company->vatNumber);
    }

    #[Test]
    public function primary_keys_take_precedence_over_alternate_keys(): void
    {
        $company = Company::fromArray([
            'registration_number' => 'PRIMARY',
            'company_number' => 'ALTERNATE',
            'vat_number' => 'VAT-PRIMARY',
            'vat_id' => 'VAT-ALTERNATE',
        ]);

        $this->assertSame('PRIMARY', $company->registrationNumber);
        $this->assertSame('VAT-PRIMARY', $company->vatNumber);
    }
}

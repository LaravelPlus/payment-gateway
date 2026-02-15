<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Tests\Unit\DTOs;

use LaravelPlus\PaymentGateway\DTOs\Address;
use LaravelPlus\PaymentGateway\DTOs\Company;
use LaravelPlus\PaymentGateway\DTOs\Customer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CustomerTest extends TestCase
{
    #[Test]
    public function it_creates_from_array_and_round_trips(): void
    {
        $data = [
            'id' => 'cus_123',
            'email' => 'john@example.com',
            'name' => 'John Doe',
            'phone' => '+1-555-0123',
            'company' => [
                'name' => 'Acme Corp',
                'vat_number' => 'VAT-123',
            ],
            'billing_address' => [
                'line1' => '123 Billing St',
                'city' => 'Billtown',
                'postal_code' => '12345',
                'country' => 'US',
            ],
            'shipping_address' => [
                'line1' => '456 Ship Ave',
                'city' => 'Shipville',
                'postal_code' => '67890',
                'country' => 'US',
            ],
            'invoice_address' => [
                'line1' => '789 Invoice Rd',
                'city' => 'Invoiceburg',
                'postal_code' => '11111',
                'country' => 'US',
            ],
            'tax_id' => 'TAX-999',
            'vat_number' => 'VAT-888',
            'default_payment_method_id' => 'pm_abc',
            'preferred_locale' => 'en',
            'metadata' => ['key' => 'value'],
        ];

        $customer = Customer::fromArray($data);

        $this->assertSame('cus_123', $customer->id);
        $this->assertSame('john@example.com', $customer->email);
        $this->assertSame('John Doe', $customer->name);
        $this->assertSame('+1-555-0123', $customer->phone);
        $this->assertInstanceOf(Company::class, $customer->company);
        $this->assertSame('Acme Corp', $customer->company->name);
        $this->assertInstanceOf(Address::class, $customer->billingAddress);
        $this->assertSame('123 Billing St', $customer->billingAddress->line1);
        $this->assertInstanceOf(Address::class, $customer->shippingAddress);
        $this->assertSame('456 Ship Ave', $customer->shippingAddress->line1);
        $this->assertInstanceOf(Address::class, $customer->invoiceAddress);
        $this->assertSame('789 Invoice Rd', $customer->invoiceAddress->line1);
        $this->assertSame('TAX-999', $customer->taxId);
        $this->assertSame('VAT-888', $customer->vatNumber);
        $this->assertSame('pm_abc', $customer->defaultPaymentMethodId);
        $this->assertSame('en', $customer->preferredLocale);
        $this->assertSame(['key' => 'value'], $customer->metadata);

        $array = $customer->toArray();
        $roundTripped = Customer::fromArray($array);

        $this->assertSame($customer->id, $roundTripped->id);
        $this->assertSame($customer->email, $roundTripped->email);
        $this->assertSame($customer->name, $roundTripped->name);
    }

    #[Test]
    public function it_creates_from_billable(): void
    {
        $billable = new class
        {
            public string $email = 'jane@example.com';

            public string $name = 'Jane Doe';

            public string $phone = '+1-555-9999';

            public string $payment_customer_id = 'cus_billable_1';

            public int $id = 42;
        };

        $customer = Customer::fromBillable($billable);

        $this->assertSame('cus_billable_1', $customer->id);
        $this->assertSame('jane@example.com', $customer->email);
        $this->assertSame('Jane Doe', $customer->name);
        $this->assertSame('+1-555-9999', $customer->phone);
        $this->assertSame(42, $customer->metadata['billable_id']);
        $this->assertArrayHasKey('billable_type', $customer->metadata);
    }

    #[Test]
    public function to_array_filters_null_and_empty_values(): void
    {
        $customer = new Customer(id: 'cus_1', email: 'test@example.com');

        $array = $customer->toArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('email', $array);
        $this->assertArrayNotHasKey('name', $array);
        $this->assertArrayNotHasKey('phone', $array);
        $this->assertArrayNotHasKey('company', $array);
        $this->assertArrayNotHasKey('billing_address', $array);
        $this->assertArrayNotHasKey('shipping_address', $array);
        $this->assertArrayNotHasKey('invoice_address', $array);
        $this->assertArrayNotHasKey('addresses', $array);
        $this->assertArrayNotHasKey('metadata', $array);
    }

    #[Test]
    public function is_business_returns_true_with_company(): void
    {
        $customer = new Customer(company: new Company(name: 'Biz Inc'));

        $this->assertTrue($customer->isBusiness());
    }

    #[Test]
    public function is_business_returns_true_with_vat_number(): void
    {
        $customer = new Customer(vatNumber: 'VAT-123');

        $this->assertTrue($customer->isBusiness());
    }

    #[Test]
    public function is_business_returns_false_without_company_or_vat(): void
    {
        $customer = new Customer(name: 'Individual Person');

        $this->assertFalse($customer->isBusiness());
    }

    #[Test]
    public function get_display_name_returns_company_name_when_available(): void
    {
        $customer = new Customer(
            name: 'John Doe',
            company: new Company(name: 'Acme Corp'),
        );

        $this->assertSame('Acme Corp', $customer->getDisplayName());
    }

    #[Test]
    public function get_display_name_returns_personal_name_without_company(): void
    {
        $customer = new Customer(name: 'John Doe');

        $this->assertSame('John Doe', $customer->getDisplayName());
    }

    #[Test]
    public function get_display_name_returns_null_when_no_names(): void
    {
        $customer = new Customer();

        $this->assertNull($customer->getDisplayName());
    }

    #[Test]
    public function get_display_name_returns_personal_name_when_company_has_no_name(): void
    {
        $customer = new Customer(
            name: 'John Doe',
            company: new Company(),
        );

        $this->assertSame('John Doe', $customer->getDisplayName());
    }

    #[Test]
    public function get_invoice_address_returns_invoice_address_when_set(): void
    {
        $invoiceAddr = new Address(line1: 'Invoice St');
        $billingAddr = new Address(line1: 'Billing St');

        $customer = new Customer(
            billingAddress: $billingAddr,
            invoiceAddress: $invoiceAddr,
        );

        $this->assertSame('Invoice St', $customer->getInvoiceAddress()->line1);
    }

    #[Test]
    public function get_invoice_address_falls_back_to_billing_address(): void
    {
        $billingAddr = new Address(line1: 'Billing St');

        $customer = new Customer(billingAddress: $billingAddr);

        $this->assertSame('Billing St', $customer->getInvoiceAddress()->line1);
    }

    #[Test]
    public function get_invoice_address_returns_null_when_no_addresses(): void
    {
        $customer = new Customer();

        $this->assertNull($customer->getInvoiceAddress());
    }

    #[Test]
    public function it_handles_nested_addresses_array(): void
    {
        $data = [
            'id' => 'cus_multi',
            'addresses' => [
                ['line1' => 'Address One', 'city' => 'CityA', 'postal_code' => '11111', 'country' => 'US'],
                ['line1' => 'Address Two', 'city' => 'CityB', 'postal_code' => '22222', 'country' => 'CA'],
            ],
        ];

        $customer = Customer::fromArray($data);

        $this->assertCount(2, $customer->addresses);
        $this->assertInstanceOf(Address::class, $customer->addresses[0]);
        $this->assertSame('Address One', $customer->addresses[0]->line1);
        $this->assertInstanceOf(Address::class, $customer->addresses[1]);
        $this->assertSame('Address Two', $customer->addresses[1]->line1);
    }
}

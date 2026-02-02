<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->number }}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            line-height: 1.5;
            color: #374151;
            margin: 0;
            padding: 40px;
        }
        .invoice-box {
            max-width: 800px;
            margin: auto;
            background: #fff;
        }
        .header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 40px;
        }
        .logo {
            font-size: 24px;
            font-weight: bold;
            color: #111827;
        }
        .invoice-title {
            font-size: 32px;
            color: #111827;
            text-align: right;
        }
        .invoice-number {
            color: #6b7280;
            text-align: right;
        }
        .status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 9999px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .status-paid {
            background: #dcfce7;
            color: #22c55e;
        }
        .status-open {
            background: #dbeafe;
            color: #3b82f6;
        }
        .status-void {
            background: #fee2e2;
            color: #ef4444;
        }
        .status-draft {
            background: #f3f4f6;
            color: #6b7280;
        }
        .addresses {
            display: flex;
            justify-content: space-between;
            margin-bottom: 40px;
        }
        .address-block {
            width: 45%;
        }
        .address-block h3 {
            margin: 0 0 8px 0;
            font-size: 12px;
            text-transform: uppercase;
            color: #6b7280;
        }
        .address-block p {
            margin: 0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 40px;
        }
        th {
            background: #f9fafb;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid #e5e5e5;
        }
        th:nth-child(2) {
            text-align: center;
        }
        th:nth-child(3),
        th:nth-child(4) {
            text-align: right;
        }
        td {
            padding: 12px;
            border-bottom: 1px solid #e5e5e5;
        }
        td:nth-child(2) {
            text-align: center;
        }
        td:nth-child(3),
        td:nth-child(4) {
            text-align: right;
        }
        .totals {
            text-align: right;
        }
        .totals table {
            width: 300px;
            margin-left: auto;
        }
        .totals td {
            padding: 8px 0;
            border: none;
        }
        .totals .total-row {
            font-size: 18px;
            font-weight: bold;
            border-top: 2px solid #111827;
        }
        .footer {
            margin-top: 60px;
            padding-top: 20px;
            border-top: 1px solid #e5e5e5;
            font-size: 12px;
            color: #6b7280;
        }
    </style>
</head>
<body>
    <div class="invoice-box">
        <div class="header">
            <div>
                <div class="logo">{{ $company['name'] }}</div>
                @if($company['address'])
                    <p style="color: #6b7280; margin: 4px 0;">{{ $company['address'] }}</p>
                @endif
                @if($company['email'])
                    <p style="color: #6b7280; margin: 4px 0;">{{ $company['email'] }}</p>
                @endif
                @if($company['phone'])
                    <p style="color: #6b7280; margin: 4px 0;">{{ $company['phone'] }}</p>
                @endif
                @if($company['vat_number'])
                    <p style="color: #6b7280; margin: 4px 0;">VAT: {{ $company['vat_number'] }}</p>
                @endif
            </div>
            <div>
                <div class="invoice-title">INVOICE</div>
                <div class="invoice-number">#{{ $invoice->number }}</div>
                <div style="margin-top: 8px;">
                    <span class="status status-{{ $invoice->status }}">{{ $invoice->status }}</span>
                </div>
            </div>
        </div>

        <div class="addresses">
            <div class="address-block">
                <h3>Bill To</h3>
                @if($invoice->billing_name)
                    <p><strong>{{ $invoice->billing_name }}</strong></p>
                @endif
                @if($invoice->billing_company)
                    <p>{{ $invoice->billing_company }}</p>
                @endif
                @if($invoice->billing_address)
                    <p>{{ $invoice->billing_address }}</p>
                @endif
                @if($invoice->billing_city || $invoice->billing_postal_code)
                    <p>{{ $invoice->billing_city }}@if($invoice->billing_city && $invoice->billing_postal_code), @endif{{ $invoice->billing_postal_code }}</p>
                @endif
                @if($invoice->billing_country)
                    <p>{{ $invoice->billing_country }}</p>
                @endif
                @if($invoice->billing_email)
                    <p>{{ $invoice->billing_email }}</p>
                @endif
                @if($invoice->tax_id)
                    <p>Tax ID: {{ $invoice->tax_id }}</p>
                @endif
            </div>
            <div class="address-block" style="text-align: right;">
                <h3>Invoice Details</h3>
                <p><strong>Invoice Date:</strong> {{ $invoice->invoice_date->format('M d, Y') }}</p>
                @if($invoice->due_date)
                    <p><strong>Due Date:</strong> {{ $invoice->due_date->format('M d, Y') }}</p>
                @else
                    <p><strong>Due Date:</strong> Upon Receipt</p>
                @endif
                <p><strong>Currency:</strong> {{ $invoice->currency }}</p>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Description</th>
                    <th>Qty</th>
                    <th>Unit Price</th>
                    <th>Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach($invoice->line_items ?? [] as $item)
                    <tr>
                        <td>{{ $item['description'] }}</td>
                        <td>{{ $item['quantity'] }}</td>
                        <td>{{ $invoice->currency }} {{ number_format($item['unit_price'] / 100, 2) }}</td>
                        <td>{{ $invoice->currency }} {{ number_format($item['amount'] / 100, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="totals">
            <table>
                <tr>
                    <td>Subtotal:</td>
                    <td>{{ $invoice->formatted_subtotal }}</td>
                </tr>
                @if($invoice->tax > 0)
                    <tr>
                        <td>Tax{{ $invoice->tax_rate ? " ({$invoice->tax_rate}%)" : '' }}:</td>
                        <td>{{ $invoice->formatted_tax }}</td>
                    </tr>
                @endif
                @if($invoice->discount > 0)
                    <tr>
                        <td>Discount:</td>
                        <td>-{{ $invoice->formatted_discount }}</td>
                    </tr>
                @endif
                <tr class="total-row">
                    <td>Total:</td>
                    <td>{{ $invoice->formatted_total }}</td>
                </tr>
                @if($invoice->amount_paid > 0)
                    <tr>
                        <td>Amount Paid:</td>
                        <td>-{{ $invoice->currency }} {{ number_format($invoice->amount_paid / 100, 2) }}</td>
                    </tr>
                @endif
                <tr style="font-weight: bold;">
                    <td>Amount Due:</td>
                    <td>{{ $invoice->formatted_amount_due }}</td>
                </tr>
            </table>
        </div>

        <div class="footer">
            @if($invoice->notes)
                <p>{{ $invoice->notes }}</p>
            @endif
            @if($invoice->footer)
                <p>{{ $invoice->footer }}</p>
            @endif
            <p style="margin-top: 20px;">Thank you for your business!</p>
        </div>
    </div>
</body>
</html>

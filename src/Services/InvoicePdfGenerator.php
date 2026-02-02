<?php

declare(strict_types=1);

namespace Nejcc\PaymentGateway\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Nejcc\PaymentGateway\Models\Invoice;

/**
 * Invoice PDF Generator Service.
 *
 * Generates PDF invoices using either DomPDF or Browsershot.
 * Falls back to HTML if no PDF library is available.
 */
final class InvoicePdfGenerator
{
    /**
     * Generate PDF for an invoice.
     */
    public function generate(Invoice $invoice): string
    {
        $html = $this->renderHtml($invoice);
        $filename = $this->getFilename($invoice);
        $path = $this->getStoragePath($invoice, $filename);

        // Try DomPDF first
        if (class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            return $this->generateWithDomPdf($invoice, $html, $path);
        }

        // Try Browsershot
        if (class_exists(\Spatie\Browsershot\Browsershot::class)) {
            return $this->generateWithBrowsershot($invoice, $html, $path);
        }

        // Fallback: save as HTML
        return $this->saveAsHtml($invoice, $html, $path);
    }

    /**
     * Generate PDF using DomPDF.
     */
    private function generateWithDomPdf(Invoice $invoice, string $html, string $path): string
    {
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html);
        $pdf->setPaper('a4', 'portrait');

        $pdfPath = str_replace('.html', '.pdf', $path);
        Storage::put($pdfPath, $pdf->output());

        $this->updateInvoice($invoice, $pdfPath);

        return $pdfPath;
    }

    /**
     * Generate PDF using Browsershot.
     */
    private function generateWithBrowsershot(Invoice $invoice, string $html, string $path): string
    {
        $pdfPath = str_replace('.html', '.pdf', $path);
        $fullPath = Storage::path($pdfPath);

        \Spatie\Browsershot\Browsershot::html($html)
            ->format('A4')
            ->margins(10, 10, 10, 10)
            ->save($fullPath);

        $this->updateInvoice($invoice, $pdfPath);

        return $pdfPath;
    }

    /**
     * Save as HTML fallback.
     */
    private function saveAsHtml(Invoice $invoice, string $html, string $path): string
    {
        Storage::put($path, $html);
        $this->updateInvoice($invoice, $path);

        return $path;
    }

    /**
     * Render invoice HTML.
     */
    public function renderHtml(Invoice $invoice): string
    {
        $viewPath = 'payment-gateway::invoices.pdf';

        // Check if custom view exists in app
        if (View::exists('invoices.pdf')) {
            $viewPath = 'invoices.pdf';
        }

        // If package view doesn't exist, use inline template
        if (!View::exists($viewPath)) {
            return $this->renderInlineTemplate($invoice);
        }

        return View::make($viewPath, [
            'invoice' => $invoice,
            'company' => $this->getCompanyDetails(),
        ])->render();
    }

    /**
     * Render inline template when no view file exists.
     */
    private function renderInlineTemplate(Invoice $invoice): string
    {
        $company = $this->getCompanyDetails();
        $lineItemsHtml = '';

        foreach ($invoice->line_items ?? [] as $item) {
            $unitPrice = number_format($item['unit_price'] / 100, 2);
            $amount = number_format($item['amount'] / 100, 2);
            $lineItemsHtml .= <<<HTML
                <tr>
                    <td style="padding: 12px; border-bottom: 1px solid #e5e5e5;">{$item['description']}</td>
                    <td style="padding: 12px; border-bottom: 1px solid #e5e5e5; text-align: center;">{$item['quantity']}</td>
                    <td style="padding: 12px; border-bottom: 1px solid #e5e5e5; text-align: right;">{$invoice->currency} {$unitPrice}</td>
                    <td style="padding: 12px; border-bottom: 1px solid #e5e5e5; text-align: right;">{$invoice->currency} {$amount}</td>
                </tr>
            HTML;
        }

        $statusColor = match ($invoice->status) {
            'paid' => '#22c55e',
            'open' => '#3b82f6',
            'void' => '#ef4444',
            default => '#6b7280',
        };

        // Pre-compute values that can't be computed inside HEREDOC
        $invoiceDate = $invoice->invoice_date->format('M d, Y');
        $dueDate = $invoice->due_date?->format('M d, Y') ?? 'Upon Receipt';
        $amountPaidFormatted = number_format($invoice->amount_paid / 100, 2);

        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <title>Invoice {$invoice->number}</title>
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.5; color: #374151; margin: 0; padding: 40px; }
                .invoice-box { max-width: 800px; margin: auto; background: #fff; }
                .header { display: flex; justify-content: space-between; margin-bottom: 40px; }
                .logo { font-size: 24px; font-weight: bold; color: #111827; }
                .invoice-title { font-size: 32px; color: #111827; text-align: right; }
                .invoice-number { color: #6b7280; text-align: right; }
                .status { display: inline-block; padding: 4px 12px; border-radius: 9999px; font-size: 12px; font-weight: 600; text-transform: uppercase; background: {$statusColor}20; color: {$statusColor}; }
                .addresses { display: flex; justify-content: space-between; margin-bottom: 40px; }
                .address-block { width: 45%; }
                .address-block h3 { margin: 0 0 8px 0; font-size: 12px; text-transform: uppercase; color: #6b7280; }
                .address-block p { margin: 0; }
                table { width: 100%; border-collapse: collapse; margin-bottom: 40px; }
                th { background: #f9fafb; padding: 12px; text-align: left; font-weight: 600; border-bottom: 2px solid #e5e5e5; }
                th:nth-child(2), th:nth-child(3), th:nth-child(4) { text-align: right; }
                th:nth-child(2) { text-align: center; }
                .totals { text-align: right; }
                .totals table { width: 300px; margin-left: auto; }
                .totals td { padding: 8px 0; }
                .totals .total-row { font-size: 18px; font-weight: bold; border-top: 2px solid #111827; }
                .footer { margin-top: 60px; padding-top: 20px; border-top: 1px solid #e5e5e5; font-size: 12px; color: #6b7280; }
            </style>
        </head>
        <body>
            <div class="invoice-box">
                <div class="header">
                    <div>
                        <div class="logo">{$company['name']}</div>
                        <p style="color: #6b7280; margin: 4px 0;">{$company['address']}</p>
                        <p style="color: #6b7280; margin: 4px 0;">{$company['email']}</p>
                    </div>
                    <div>
                        <div class="invoice-title">INVOICE</div>
                        <div class="invoice-number">#{$invoice->number}</div>
                        <div style="margin-top: 8px;"><span class="status">{$invoice->status}</span></div>
                    </div>
                </div>

                <div class="addresses">
                    <div class="address-block">
                        <h3>Bill To</h3>
                        <p><strong>{$invoice->billing_name}</strong></p>
                        <p>{$invoice->billing_company}</p>
                        <p>{$invoice->billing_address}</p>
                        <p>{$invoice->billing_city}, {$invoice->billing_postal_code}</p>
                        <p>{$invoice->billing_country}</p>
                        <p>{$invoice->billing_email}</p>
                    </div>
                    <div class="address-block" style="text-align: right;">
                        <h3>Invoice Details</h3>
                        <p><strong>Invoice Date:</strong> {$invoiceDate}</p>
                        <p><strong>Due Date:</strong> {$dueDate}</p>
                        <p><strong>Currency:</strong> {$invoice->currency}</p>
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
                        {$lineItemsHtml}
                    </tbody>
                </table>

                <div class="totals">
                    <table>
                        <tr>
                            <td>Subtotal:</td>
                            <td>{$invoice->formatted_subtotal}</td>
                        </tr>
                        <tr>
                            <td>Tax ({$invoice->tax_rate}%):</td>
                            <td>{$invoice->formatted_tax}</td>
                        </tr>
                        <tr class="total-row">
                            <td>Total:</td>
                            <td>{$invoice->formatted_total}</td>
                        </tr>
                        <tr>
                            <td>Amount Paid:</td>
                            <td>-{$invoice->currency} {$amountPaidFormatted}</td>
                        </tr>
                        <tr style="font-weight: bold;">
                            <td>Amount Due:</td>
                            <td>{$invoice->formatted_amount_due}</td>
                        </tr>
                    </table>
                </div>

                <div class="footer">
                    <p>{$invoice->notes}</p>
                    <p>{$invoice->footer}</p>
                    <p style="margin-top: 20px;">Thank you for your business!</p>
                </div>
            </div>
        </body>
        </html>
        HTML;
    }

    /**
     * Get company details from config.
     *
     * @return array<string, string>
     */
    private function getCompanyDetails(): array
    {
        return [
            'name' => config('payment-gateway.invoice.company_name', config('app.name')),
            'address' => config('payment-gateway.invoice.company_address', ''),
            'email' => config('payment-gateway.invoice.company_email', config('mail.from.address')),
            'phone' => config('payment-gateway.invoice.company_phone', ''),
            'vat_number' => config('payment-gateway.invoice.company_vat', ''),
            'logo' => config('payment-gateway.invoice.logo_url', ''),
        ];
    }

    /**
     * Get filename for invoice PDF.
     */
    private function getFilename(Invoice $invoice): string
    {
        return sprintf('invoice-%s.pdf', $invoice->number);
    }

    /**
     * Get storage path for invoice.
     */
    private function getStoragePath(Invoice $invoice, string $filename): string
    {
        $directory = config('payment-gateway.invoice.storage_path', 'invoices');

        return sprintf('%s/%s/%s', $directory, $invoice->created_at->format('Y/m'), $filename);
    }

    /**
     * Update invoice with PDF path.
     */
    private function updateInvoice(Invoice $invoice, string $path): void
    {
        $invoice->update([
            'pdf_path' => $path,
            'pdf_generated_at' => now(),
        ]);
    }
}

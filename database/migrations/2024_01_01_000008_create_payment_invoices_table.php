<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payment_invoices', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Relations
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('payment_subscriptions')->nullOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained('payment_transactions')->nullOnDelete();

            // Invoice identification
            $table->string('number')->unique(); // INV-2024-0001
            $table->string('driver', 50);
            $table->string('provider_id')->nullable()->index(); // Stripe invoice ID

            // Status
            $table->string('status', 30)->default('draft'); // draft, open, paid, void, uncollectible

            // Amounts (in cents!)
            $table->unsignedBigInteger('subtotal');
            $table->unsignedBigInteger('tax')->default(0);
            $table->unsignedBigInteger('discount')->default(0);
            $table->unsignedBigInteger('total');
            $table->unsignedBigInteger('amount_paid')->default(0);
            $table->unsignedBigInteger('amount_due');
            $table->string('currency', 3);

            // Tax info
            $table->decimal('tax_rate', 5, 2)->nullable();
            $table->string('tax_id')->nullable(); // Customer VAT number

            // Billing details (snapshot at invoice time)
            $table->string('billing_name')->nullable();
            $table->string('billing_email')->nullable();
            $table->string('billing_address')->nullable();
            $table->string('billing_city')->nullable();
            $table->string('billing_state')->nullable();
            $table->string('billing_postal_code')->nullable();
            $table->string('billing_country', 2)->nullable();
            $table->string('billing_company')->nullable();

            // Dates
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('voided_at')->nullable();

            // PDF
            $table->string('pdf_path')->nullable();
            $table->timestamp('pdf_generated_at')->nullable();

            // Line items stored as JSON
            $table->json('line_items'); // [{description, quantity, unit_price, amount}]

            // Notes
            $table->text('notes')->nullable();
            $table->text('footer')->nullable();

            // Metadata
            $table->json('metadata')->nullable();
            $table->json('provider_response')->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['user_id', 'status']);
            $table->index(['invoice_date', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_invoices');
    }
};

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
        Schema::create('payment_customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Provider IDs
            $table->string('stripe_id')->nullable()->index();
            $table->string('paypal_id')->nullable()->index();
            $table->string('crypto_id')->nullable()->index();

            // Basic info
            $table->string('email');
            $table->string('name')->nullable();
            $table->string('phone')->nullable();
            $table->string('preferred_locale', 10)->nullable();

            // Tax info
            $table->string('tax_id')->nullable();
            $table->string('vat_number')->nullable();

            // Company info (JSON for flexibility)
            $table->json('company')->nullable();

            // Addresses (JSON arrays)
            $table->json('billing_address')->nullable();
            $table->json('shipping_address')->nullable();
            $table->json('invoice_address')->nullable();

            // Default payment method per provider
            $table->string('default_payment_method')->nullable();

            // Flags
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_business')->default(false);

            // Metadata
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['user_id', 'is_primary']);
            $table->index('email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_customers');
    }
};

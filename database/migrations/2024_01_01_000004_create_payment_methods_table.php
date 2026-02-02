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
        Schema::create('payment_methods', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Relations
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('payment_customer_id')->nullable()->constrained()->cascadeOnDelete();

            // Provider info
            $table->string('driver', 50);
            $table->string('provider_id')->index();

            // Method type
            $table->string('type', 50); // card, bank_account, paypal, crypto_wallet, etc.

            // Card details (for cards)
            $table->string('card_brand')->nullable(); // visa, mastercard, amex, etc.
            $table->string('card_last_four', 4)->nullable();
            $table->unsignedSmallInteger('card_exp_month')->nullable();
            $table->unsignedSmallInteger('card_exp_year')->nullable();

            // Bank details (for bank transfers)
            $table->string('bank_name')->nullable();
            $table->string('bank_last_four', 4)->nullable();

            // Crypto details
            $table->string('crypto_currency')->nullable();
            $table->string('crypto_address')->nullable();

            // PayPal
            $table->string('paypal_email')->nullable();

            // Billing address
            $table->json('billing_address')->nullable();

            // Flags
            $table->boolean('is_default')->default(false);

            // Metadata
            $table->json('metadata')->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['user_id', 'is_default']);
            $table->index(['driver', 'provider_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};

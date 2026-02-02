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
        Schema::create('payment_transactions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Relations
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_customer_id')->nullable()->constrained()->nullOnDelete();

            // Provider info
            $table->string('driver', 50); // stripe, paypal, crypto, etc.
            $table->string('provider_id')->nullable()->index(); // Provider's transaction ID
            $table->string('provider_payment_method_id')->nullable();

            // Amount (always in cents!)
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('amount_refunded')->default(0);
            $table->string('currency', 3);

            // Status
            $table->string('status', 50)->default('pending');

            // Type
            $table->string('type', 50)->default('charge'); // charge, refund, subscription, etc.

            // Description
            $table->string('description')->nullable();

            // Failure info
            $table->string('failure_code')->nullable();
            $table->text('failure_message')->nullable();

            // Receipt
            $table->string('receipt_url')->nullable();

            // Related models (polymorphic)
            $table->nullableMorphs('payable'); // order_id, subscription_id, etc.

            // Metadata
            $table->json('metadata')->nullable();
            $table->json('provider_response')->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['user_id', 'status']);
            $table->index(['driver', 'status']);
            $table->index(['driver', 'provider_id']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};

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
        Schema::create('payment_refunds', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Relations
            $table->foreignId('transaction_id')->constrained('payment_transactions')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Provider info
            $table->string('driver', 50);
            $table->string('provider_id')->nullable()->index();

            // Amount (in cents!)
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3);

            // Status
            $table->string('status', 50)->default('pending');

            // Reason
            $table->string('reason')->nullable();
            $table->text('failure_reason')->nullable();

            // Metadata
            $table->json('metadata')->nullable();
            $table->json('provider_response')->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['transaction_id', 'status']);
            $table->index(['driver', 'provider_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_refunds');
    }
};

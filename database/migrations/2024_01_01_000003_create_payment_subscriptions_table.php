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
        Schema::create('payment_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Relations
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_customer_id')->nullable()->constrained()->nullOnDelete();

            // Provider info
            $table->string('driver', 50);
            $table->string('provider_id')->index();
            $table->string('provider_plan_id');

            // Plan info
            $table->string('plan_id'); // Your internal plan ID
            $table->string('plan_name')->nullable();

            // Pricing (in cents!)
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3);
            $table->string('interval', 20); // day, week, month, year
            $table->unsignedSmallInteger('interval_count')->default(1);

            // Status
            $table->string('status', 50)->default('incomplete');

            // Quantity (for per-seat pricing)
            $table->unsignedInteger('quantity')->default(1);

            // Billing periods
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();

            // Trial
            $table->timestamp('trial_start')->nullable();
            $table->timestamp('trial_end')->nullable();

            // Cancellation
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);

            // Pause
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('resume_at')->nullable();

            // Metadata
            $table->json('metadata')->nullable();
            $table->json('provider_response')->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['user_id', 'status']);
            $table->index(['driver', 'provider_id']);
            $table->index(['plan_id', 'status']);
            $table->index('current_period_end');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_subscriptions');
    }
};

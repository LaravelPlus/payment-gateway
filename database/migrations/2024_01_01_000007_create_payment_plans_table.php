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
        Schema::create('payment_plans', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Identification
            $table->string('slug')->unique(); // e.g., 'basic', 'pro', 'enterprise'
            $table->string('name'); // Display name: 'Basic Plan'
            $table->text('description')->nullable();

            // Pricing (in cents!)
            $table->unsignedBigInteger('amount'); // Price in cents
            $table->string('currency', 3)->default('EUR');

            // Billing interval
            $table->string('interval', 20); // day, week, month, year
            $table->unsignedSmallInteger('interval_count')->default(1);

            // Trial
            $table->unsignedSmallInteger('trial_days')->nullable();

            // Features & limits
            $table->json('features')->nullable(); // ['feature1', 'feature2']
            $table->json('limits')->nullable(); // {'users': 5, 'storage_gb': 10}
            $table->json('metadata')->nullable();

            // Provider IDs (sync with payment providers)
            $table->string('stripe_price_id')->nullable()->index();
            $table->string('stripe_product_id')->nullable();
            $table->string('paypal_plan_id')->nullable()->index();

            // Status & ordering
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);

            // Visibility
            $table->boolean('is_public')->default(true); // Show on pricing page
            $table->boolean('is_archived')->default(false);

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['is_active', 'is_public', 'sort_order']);
            $table->index('interval');
        });

        // Add foreign key to subscriptions table
        Schema::table('payment_subscriptions', function (Blueprint $table): void {
            $table->foreignId('payment_plan_id')
                ->nullable()
                ->after('payment_customer_id')
                ->constrained('payment_plans')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_subscriptions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('payment_plan_id');
        });

        Schema::dropIfExists('payment_plans');
    }
};

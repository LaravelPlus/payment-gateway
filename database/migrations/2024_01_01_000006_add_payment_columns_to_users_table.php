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
        Schema::table('users', function (Blueprint $table): void {
            $table->string('stripe_id')->nullable()->index()->after('remember_token');
            $table->string('paypal_id')->nullable()->index()->after('stripe_id');
            $table->string('payment_customer_id')->nullable()->after('paypal_id');
            $table->timestamp('trial_ends_at')->nullable()->after('payment_customer_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'stripe_id',
                'paypal_id',
                'payment_customer_id',
                'trial_ends_at',
            ]);
        });
    }
};

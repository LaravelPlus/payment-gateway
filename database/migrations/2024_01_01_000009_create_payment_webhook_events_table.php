<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ledger of webhook event ids we have already processed, keyed by
 * "<driver>:<event id>" so ids from different providers cannot collide.
 * Providers retry on any non-2xx, on timeout, and occasionally on their own,
 * so the same event arrives more than once. The primary key is what makes
 * the dedupe atomic — two concurrent deliveries cannot both win the insert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_webhook_events', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
    }
};

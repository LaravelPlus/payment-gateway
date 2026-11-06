<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * PaymentCustomer uses HasUuid (and routes by uuid), but the table never had
 * the column, so every PaymentCustomer::create() failed (gh-15).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Some host apps added the column themselves to work around this.
        if (Schema::hasColumn('payment_customers', 'uuid')) {
            return;
        }

        Schema::table('payment_customers', function (Blueprint $table): void {
            $table->uuid('uuid')->nullable()->after('id');
        });

        // ponytail: row-by-row backfill; fine for customer tables, chunk it if one has millions of rows.
        DB::table('payment_customers')->whereNull('uuid')->orderBy('id')->each(function (object $row): void {
            DB::table('payment_customers')->where('id', $row->id)->update(['uuid' => (string) Str::uuid()]);
        });

        Schema::table('payment_customers', function (Blueprint $table): void {
            $table->unique('uuid');
        });
    }

    public function down(): void
    {
        Schema::table('payment_customers', function (Blueprint $table): void {
            $table->dropUnique(['uuid']);
            $table->dropColumn('uuid');
        });
    }
};

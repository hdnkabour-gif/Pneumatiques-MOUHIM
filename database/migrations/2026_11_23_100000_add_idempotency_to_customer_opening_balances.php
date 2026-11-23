<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #19: an opening balance submitted twice (double click, retry after an error) is recorded once.
 * Optional operation key + request fingerprint, like customer receipts (#21). Additive: existing
 * rows keep NULL and are never changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_opening_balances', function (Blueprint $table) {
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->char('idempotency_fingerprint', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customer_opening_balances', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['idempotency_key', 'idempotency_fingerprint']);
        });
    }
};

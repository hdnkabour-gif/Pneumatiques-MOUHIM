<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * #21: an operation key for customer receipts. A retry of the SAME operation carries
 * the same key and must not post twice; two genuine receipts carry different keys.
 * Additive and nullable: existing payments keep NULL (unique ignores NULLs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->char('idempotency_fingerprint', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['idempotency_key', 'idempotency_fingerprint']);
        });
    }
};

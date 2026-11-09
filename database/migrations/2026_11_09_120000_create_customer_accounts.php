<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4: opening balances (D-23), payments allocated to several documents
 * (D-20), cheque follow-up with rejection restoring the debt (D-22).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_opening_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount_cents');
            $table->bigInteger('remaining_cents');
            $table->date('as_of');
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->foreignId('opening_balance_id')->nullable()->constrained('customer_opening_balances')->restrictOnDelete();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('deposited_at')->nullable();
            $table->timestamp('cleared_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason')->nullable();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE customer_opening_balances ADD CONSTRAINT opening_balances_valid CHECK (amount_cents > 0 AND remaining_cents >= 0 AND remaining_cents <= amount_cents);
            ALTER TABLE payment_allocations ALTER COLUMN sale_id DROP NOT NULL;
            ALTER TABLE payment_allocations ADD CONSTRAINT payment_allocations_target CHECK (sale_id IS NOT NULL OR opening_balance_id IS NOT NULL);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE payment_allocations DROP CONSTRAINT IF EXISTS payment_allocations_target;');
        Schema::table('payments', fn (Blueprint $t) => $t->dropColumn(['deposited_at', 'cleared_at', 'rejected_at', 'rejection_reason']));
        Schema::table('payment_allocations', fn (Blueprint $t) => $t->dropConstrainedForeignId('opening_balance_id'));
        Schema::dropIfExists('customer_opening_balances');
    }
};

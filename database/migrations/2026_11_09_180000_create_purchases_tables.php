<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6: suppliers, purchase orders (optional), receipts (total or partial)
 * that bring stock in at their cost and create the supplier debt, payments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('ice', 20)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('address')->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            $table->bigInteger('opening_balance_cents')->default(0); // D-23
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        DB::statement('CREATE INDEX suppliers_name_lower_index ON suppliers (lower(name))');

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->index(); // ordered | partial | received | cancelled
            $table->date('expected_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity_ordered');
            $table->unsignedInteger('quantity_received')->default(0);
            $table->bigInteger('unit_cost_cents'); // HT
        });

        Schema::create('purchase_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->string('invoice_ref', 60)->nullable();
            $table->bigInteger('total_ht_cents');
            $table->unsignedInteger('vat_rate_bp');
            $table->bigInteger('total_ttc_cents'); // what is owed to the supplier
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('received_at');
            $table->timestamps();
        });

        Schema::create('purchase_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->bigInteger('unit_cost_cents');
            $table->unsignedTinyInteger('dot_week')->nullable();
            $table->unsignedSmallInteger('dot_year')->nullable();
        });

        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount_cents');
            $table->string('method', 20);
            $table->string('reference', 60)->nullable();
            $table->date('paid_at');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE purchase_order_lines ADD CONSTRAINT po_lines_valid CHECK (quantity_ordered > 0 AND quantity_received >= 0 AND unit_cost_cents >= 0);
            ALTER TABLE purchase_receipt_lines ADD CONSTRAINT receipt_lines_valid CHECK (quantity > 0 AND unit_cost_cents >= 0);
            ALTER TABLE supplier_payments ADD CONSTRAINT supplier_payments_positive CHECK (amount_cents > 0);
        SQL);
    }

    public function down(): void
    {
        foreach (['supplier_payments', 'purchase_receipt_lines', 'purchase_receipts', 'purchase_order_lines', 'purchase_orders'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::dropIfExists('suppliers');
    }
};

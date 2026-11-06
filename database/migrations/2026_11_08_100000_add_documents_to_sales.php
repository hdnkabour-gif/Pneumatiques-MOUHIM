<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3b: quotes, delivery / work notes and recap invoices, credit notes
 * (returns, exchanges) and trade-ins share the sales tables: one document
 * model, one immutability rule (D-15).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('kind', 20)->default('invoice')->index(); // App\Enums\SaleKind
            $table->date('valid_until')->nullable();                 // quotes
            $table->string('quote_status', 20)->nullable();          // App\Enums\QuoteStatus
            $table->boolean('reserved')->default(false);             // quote holding stock (D-13)
            $table->foreignId('quote_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->foreignId('recap_invoice_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->foreignId('original_sale_id')->nullable()->constrained('sales')->restrictOnDelete(); // credit note → invoice
            $table->string('refund_method', 20)->nullable();         // App\Enums\RefundMethod
            $table->string('reason')->nullable();
        });

        Schema::table('sale_lines', function (Blueprint $table) {
            $table->foreignId('original_line_id')->nullable()->constrained('sale_lines')->restrictOnDelete();
            $table->boolean('restock')->nullable();     // credit note: back in stock / damaged
            $table->string('trade_in_type', 20)->nullable(); // tyre | battery
            $table->json('meta')->nullable();           // trade-in tyre details
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('credit_note_id')->nullable()->constrained('sales')->restrictOnDelete();
        });

        // A trade-in line has no catalogue item.
        DB::unprepared("ALTER TABLE sale_lines DROP CONSTRAINT sale_lines_item;
            ALTER TABLE sale_lines ADD CONSTRAINT sale_lines_item CHECK (product_id IS NOT NULL OR used_tyre_id IS NOT NULL OR kind = 'trade_in');");
    }

    public function down(): void
    {
        DB::unprepared("DELETE FROM sale_lines WHERE kind = 'trade_in'; ALTER TABLE sale_lines DROP CONSTRAINT sale_lines_item;
            ALTER TABLE sale_lines ADD CONSTRAINT sale_lines_item CHECK (product_id IS NOT NULL OR used_tyre_id IS NOT NULL);");
        Schema::table('payments', fn (Blueprint $t) => $t->dropConstrainedForeignId('credit_note_id'));
        Schema::table('sale_lines', function (Blueprint $t) {
            $t->dropConstrainedForeignId('original_line_id');
            $t->dropColumn(['restock', 'trade_in_type', 'meta']);
        });
        Schema::table('sales', function (Blueprint $t) {
            foreach (['quote_id', 'recap_invoice_id', 'original_sale_id'] as $c) {
                $t->dropConstrainedForeignId($c);
            }
            $t->dropColumn(['kind', 'valid_until', 'quote_status', 'reserved', 'refund_method', 'reason']);
        });
    }
};

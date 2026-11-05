<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stock ledger (D-08): stock_movements is the source of truth and is
 * append-only; stock_levels and tyre_lots.quantity are caches updated in the
 * same transaction and checked by `php artisan stock:verify`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tyre_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('dot_week')->nullable();
            $table->unsignedSmallInteger('dot_year')->nullable();
            $table->integer('quantity')->default(0);
            $table->bigInteger('unit_cost_cents')->nullable();
            $table->string('supplier')->nullable();
            $table->date('received_at');
            $table->foreignId('source_lot_id')->nullable()->constrained('tyre_lots')->nullOnDelete();
            $table->timestamps();

            $table->index(['product_id', 'site_id']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('used_tyre_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('tyre_lot_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->string('type', 30); // App\Enums\StockMovementType
            $table->integer('quantity'); // signed, never 0
            $table->bigInteger('unit_cost_cents')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason')->nullable();
            $table->nullableMorphs('document');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['product_id', 'site_id', 'created_at']);
            $table->index(['site_id', 'type', 'created_at']);
        });

        Schema::create('stock_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->integer('quantity')->default(0);
            $table->integer('reserved')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'site_id']);
        });

        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->morphs('reservable');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_quantity_not_zero CHECK (quantity <> 0);
            ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_item CHECK (product_id IS NOT NULL OR used_tyre_id IS NOT NULL);
            ALTER TABLE stock_levels ADD CONSTRAINT stock_levels_quantity_not_negative CHECK (quantity >= 0);
            ALTER TABLE stock_levels ADD CONSTRAINT stock_levels_reserved_valid CHECK (reserved >= 0);
            ALTER TABLE tyre_lots ADD CONSTRAINT tyre_lots_quantity_not_negative CHECK (quantity >= 0);

            CREATE OR REPLACE FUNCTION stock_movements_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'stock_movements is append-only';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER stock_movements_no_update_delete
                BEFORE UPDATE OR DELETE ON stock_movements
                FOR EACH ROW EXECUTE FUNCTION stock_movements_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS stock_movements_no_update_delete ON stock_movements; DROP FUNCTION IF EXISTS stock_movements_append_only();');
        Schema::dropIfExists('stock_reservations');
        Schema::dropIfExists('stock_levels');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('tyre_lots');
    }
};

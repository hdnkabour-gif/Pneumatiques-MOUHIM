<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-06 revised: oil is sold in sealed cans (existing stock) AND by the
 * litre from drums. Each drum has its own record and its own append-only
 * ledger; quantities are integer millilitres (3 decimals of a litre, exact).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oil_drums', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->foreignId('product_id')->constrained()->restrictOnDelete(); // bulk product (unit = litre)
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->index(); // App\Enums\OilDrumStatus
            $table->unsignedInteger('capacity_ml');   // nominal (e.g. 208 L)
            $table->unsignedInteger('received_ml');   // really received
            $table->integer('remaining_ml');          // cache of the ledger
            $table->bigInteger('purchase_cost_cents');
            $table->bigInteger('extra_cost_cents')->default(0); // transport, fees
            $table->bigInteger('cost_per_litre_cents');
            $table->string('supplier')->nullable();
            $table->string('invoice_ref', 60)->nullable();
            $table->date('received_at');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('emptied_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('oil_drum_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('oil_drum_id')->constrained()->restrictOnDelete();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->string('type', 20); // App\Enums\OilDrumMovementType
            $table->integer('quantity_ml'); // signed
            $table->bigInteger('cost_per_litre_cents');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason')->nullable();
            $table->nullableMorphs('document');
            $table->timestamp('created_at');
        });

        Schema::table('sale_lines', function (Blueprint $table) {
            $table->foreignId('oil_drum_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity_ml')->nullable(); // bulk oil line: litres × 1000
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE oil_drums ADD CONSTRAINT oil_drums_remaining_valid CHECK (remaining_ml >= 0 AND received_ml > 0);
            ALTER TABLE oil_drum_movements ADD CONSTRAINT oil_drum_movements_not_zero CHECK (quantity_ml <> 0);
            CREATE OR REPLACE FUNCTION oil_drum_movements_append_only() RETURNS trigger AS $$
            BEGIN RAISE EXCEPTION 'oil drum movements are append-only: record a correction instead'; END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER oil_drum_movements_no_update_delete BEFORE UPDATE OR DELETE ON oil_drum_movements
                FOR EACH ROW EXECUTE FUNCTION oil_drum_movements_append_only();
            ALTER TABLE sale_lines DROP CONSTRAINT sale_lines_item;
            ALTER TABLE sale_lines ADD CONSTRAINT sale_lines_item CHECK (product_id IS NOT NULL OR used_tyre_id IS NOT NULL OR kind = 'trade_in');
        SQL);
    }

    public function down(): void
    {
        Schema::table('sale_lines', function (Blueprint $t) {
            $t->dropConstrainedForeignId('oil_drum_id');
            $t->dropColumn('quantity_ml');
        });
        DB::unprepared('DROP TRIGGER IF EXISTS oil_drum_movements_no_update_delete ON oil_drum_movements; DROP FUNCTION IF EXISTS oil_drum_movements_append_only();');
        Schema::dropIfExists('oil_drum_movements');
        Schema::dropIfExists('oil_drums');
    }
};

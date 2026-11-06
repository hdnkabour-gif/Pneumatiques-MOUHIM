<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Phase 3a: customers, their vehicles, sales (invoices), payments at sale, work orders. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->default('individual'); // App\Enums\CustomerType
            $table->string('name');
            $table->string('phone', 30)->nullable();
            $table->string('phone_normalized', 20)->nullable()->index();
            $table->string('email')->nullable();
            $table->string('ice', 20)->nullable()->index();
            $table->string('tax_id', 20)->nullable(); // IF
            $table->string('trade_register', 30)->nullable(); // RC
            $table->string('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('price_level', 20)->default('retail'); // retail | pro | reseller
            $table->bigInteger('credit_limit_cents')->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            $table->boolean('requires_po_number')->default(false);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        DB::statement('CREATE INDEX customers_name_lower_index ON customers (lower(name))');

        Schema::create('customer_vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('plate', 30);
            $table->string('plate_normalized', 30)->index();
            $table->foreignId('vehicle_model_id')->nullable()->constrained('vehicle_models')->nullOnDelete();
            $table->foreignId('vehicle_engine_id')->nullable()->constrained('vehicle_engines')->nullOnDelete();
            $table->string('description')->nullable(); // free text when the model is not in the reference
            $table->unsignedSmallInteger('year')->nullable();
            $table->unsignedInteger('mileage')->nullable();
            $table->string('tyre_size_front', 30)->nullable();
            $table->string('tyre_size_rear', 30)->nullable();
            $table->unsignedInteger('next_oil_change_km')->nullable();
            $table->date('next_oil_change_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->nullable()->unique(); // given at confirmation (D-14)
            $table->string('status', 20)->index(); // App\Enums\SaleStatus
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('customer_vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('mileage')->nullable();
            $table->string('price_level', 20)->default('retail');
            $table->string('po_number', 60)->nullable();
            $table->bigInteger('total_ht_cents')->default(0);
            $table->bigInteger('total_vat_cents')->default(0);
            $table->bigInteger('total_ttc_cents')->default(0);
            $table->bigInteger('discount_cents')->default(0);
            $table->bigInteger('paid_cents')->default(0);
            $table->bigInteger('credit_cents')->default(0); // left to pay (customer debt)
            $table->foreignId('seller_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable()->index();
            $table->json('approvals')->nullable(); // granted approval ids by type
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('sale_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('used_tyre_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('bundle_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('kind', 20); // goods | service | used_tyre
            $table->string('description');
            $table->unsignedInteger('quantity');
            $table->bigInteger('list_price_ttc_cents'); // catalogue price for the customer's level
            $table->bigInteger('unit_price_ttc_cents'); // after discount
            $table->unsignedInteger('discount_bp')->default(0);
            $table->unsignedInteger('vat_rate_bp'); // copied on each line (D-19)
            $table->bigInteger('total_ht_cents');
            $table->bigInteger('total_vat_cents');
            $table->bigInteger('total_ttc_cents');
            $table->bigInteger('unit_cost_cents')->nullable(); // snapshot at confirmation, NULL = unknown
            $table->unsignedSmallInteger('sort_order')->default(0);
        });

        // Payments (phase 4 adds customer accounts, cheque follow-up and cash register on top).
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('method', 20); // App\Enums\PaymentMethod
            $table->bigInteger('amount_cents');
            $table->string('cheque_number', 40)->nullable();
            $table->string('bank', 100)->nullable();
            $table->string('drawer', 150)->nullable();
            $table->date('due_date')->nullable();
            $table->string('status', 20)->default('received');
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at');
            $table->timestamps();
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount_cents');
        });

        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('mileage')->nullable();
            $table->string('status', 20)->index(); // App\Enums\WorkOrderStatus
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('user_work_order', function (Blueprint $table) {
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['work_order_id', 'user_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE payments ADD CONSTRAINT payments_amount_positive CHECK (amount_cents > 0);
            ALTER TABLE sale_lines ADD CONSTRAINT sale_lines_quantity_positive CHECK (quantity > 0);
            ALTER TABLE sale_lines ADD CONSTRAINT sale_lines_item CHECK (product_id IS NOT NULL OR used_tyre_id IS NOT NULL);

            -- A confirmed invoice is never modified nor deleted (§4, D-15): corrections go through credit notes.
            CREATE OR REPLACE FUNCTION sales_protect_confirmed() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' AND OLD.status <> 'draft' THEN
                    RAISE EXCEPTION 'confirmed sale % cannot be deleted', OLD.number;
                END IF;
                IF TG_OP = 'UPDATE' AND OLD.status <> 'draft' AND (
                    NEW.number IS DISTINCT FROM OLD.number OR NEW.site_id <> OLD.site_id
                    OR NEW.customer_id IS DISTINCT FROM OLD.customer_id OR NEW.total_ttc_cents <> OLD.total_ttc_cents
                    OR NEW.total_ht_cents <> OLD.total_ht_cents OR NEW.total_vat_cents <> OLD.total_vat_cents
                    OR NEW.confirmed_at IS DISTINCT FROM OLD.confirmed_at
                ) THEN
                    RAISE EXCEPTION 'confirmed sale % cannot be modified', OLD.number;
                END IF;
                IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER sales_protect_confirmed BEFORE UPDATE OR DELETE ON sales
                FOR EACH ROW EXECUTE FUNCTION sales_protect_confirmed();

            CREATE OR REPLACE FUNCTION sale_lines_protect_confirmed() RETURNS trigger AS $$
            DECLARE s text;
            BEGIN
                SELECT status INTO s FROM sales WHERE id = COALESCE(NEW.sale_id, OLD.sale_id);
                IF s IS NOT NULL AND s <> 'draft' THEN
                    RAISE EXCEPTION 'lines of a confirmed sale cannot be changed';
                END IF;
                IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER sale_lines_protect_confirmed BEFORE INSERT OR UPDATE OR DELETE ON sale_lines
                FOR EACH ROW EXECUTE FUNCTION sale_lines_protect_confirmed();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS sale_lines_protect_confirmed ON sale_lines; DROP FUNCTION IF EXISTS sale_lines_protect_confirmed();
            DROP TRIGGER IF EXISTS sales_protect_confirmed ON sales; DROP FUNCTION IF EXISTS sales_protect_confirmed();');
        foreach (['user_work_order', 'work_orders', 'payment_allocations', 'payments', 'sale_lines', 'sales', 'customer_vehicles', 'customers'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};

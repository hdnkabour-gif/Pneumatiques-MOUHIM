<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->string('type', 30); // App\Enums\CategoryType
            $table->string('sku_prefix', 4);
            $table->unsignedInteger('default_margin_bp')->nullable(); // D-40: empty until set
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['parent_id', 'name']);
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 20)->unique();
            $table->string('kind', 20); // App\Enums\ProductKind
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('name_ar')->nullable(); // services only (D-44)
            $table->string('manufacturer_ref', 100)->nullable()->index();
            $table->string('unit', 20)->default('piece');
            $table->bigInteger('price_retail_cents');
            $table->bigInteger('price_pro_cents')->nullable();
            $table->bigInteger('price_reseller_cents')->nullable();
            $table->bigInteger('reference_cost_cents')->nullable();
            // Company-wide weighted average cost (D-09). NULL = unknown, never 0.
            $table->bigInteger('average_cost_cents')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['kind', 'is_active']);
        });
        DB::statement('CREATE INDEX products_name_lower_index ON products (lower(name))');

        Schema::create('product_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 100);
            $table->string('brand')->nullable();
            $table->timestamps();

            $table->index('reference');
        });

        Schema::create('tyre_specs', function (Blueprint $table) {
            $table->foreignId('product_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('aspect_ratio')->nullable();
            $table->decimal('rim_diameter', 4, 1);
            $table->string('load_index', 10)->nullable();
            $table->string('speed_index', 3)->nullable();
            $table->string('season', 20)->nullable();
            $table->boolean('is_xl')->default(false);
            $table->boolean('is_run_flat')->default(false);
            $table->boolean('is_reinforced')->default(false);
            $table->string('usage', 20)->nullable();
            $table->string('pattern')->nullable();
            $table->string('oe_marking', 50)->nullable();
            $table->string('origin_country', 50)->nullable();
            $table->unsignedSmallInteger('warranty_months')->nullable();

            $table->index(['width', 'aspect_ratio', 'rim_diameter']);
        });

        Schema::create('oil_specs', function (Blueprint $table) {
            $table->foreignId('product_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('viscosity', 20)->nullable()->index();
            $table->string('oil_type', 20)->nullable();
            $table->unsignedInteger('volume_ml')->nullable();
            $table->string('standards')->nullable();
            $table->string('oem_approvals')->nullable();
        });

        Schema::create('battery_specs', function (Blueprint $table) {
            $table->foreignId('product_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('voltage')->default(12);
            $table->unsignedSmallInteger('capacity_ah')->nullable();
            $table->unsignedSmallInteger('cca')->nullable();
            $table->string('dimensions', 50)->nullable();
            $table->string('polarity', 10)->nullable();
            $table->string('technology', 20)->nullable();
            $table->unsignedSmallInteger('warranty_months')->nullable();
        });

        Schema::create('filter_specs', function (Blueprint $table) {
            $table->foreignId('product_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('filter_type', 20)->nullable();
        });

        Schema::create('lighting_specs', function (Blueprint $table) {
            $table->foreignId('product_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('base', 20)->nullable()->index();
            $table->unsignedSmallInteger('power_w')->nullable();
            $table->unsignedSmallInteger('voltage')->nullable();
            $table->string('usage', 30)->nullable();
        });

        Schema::create('product_site_thresholds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('min_quantity');
            $table->unique(['product_id', 'site_id']);
        });

        Schema::create('service_price_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('min_rim_diameter', 4, 1);
            $table->decimal('max_rim_diameter', 4, 1)->nullable();
            $table->bigInteger('price_cents');
        });

        Schema::create('service_consumables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->unsignedInteger('quantity');
        });

        Schema::create('product_site', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->primary(['product_id', 'site_id']);
        });

        Schema::create('bundle_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bundle_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            // Share of the bundle price allocated to this line (decomposition on invoices).
            $table->bigInteger('unit_price_cents');
            $table->unsignedSmallInteger('sort_order')->default(0);
        });
    }

    public function down(): void
    {
        foreach (['bundle_items', 'product_site', 'service_consumables', 'service_price_tiers', 'product_site_thresholds',
            'lighting_specs', 'filter_specs', 'battery_specs', 'oil_specs', 'tyre_specs', 'product_references',
            'products', 'brands', 'categories'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

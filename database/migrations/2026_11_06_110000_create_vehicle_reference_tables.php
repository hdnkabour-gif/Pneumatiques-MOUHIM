<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vehicle reference (§7.1) and verified compatibilities (§7.3, D-24, D-48).
 * external_id keeps the workbook identifiers (shared with Car Rental Manager).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_makes', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('vehicle_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('make_id')->constrained('vehicle_makes')->restrictOnDelete();
            $table->string('name');
            $table->string('body_type')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['make_id', 'name']);
        });

        Schema::create('vehicle_generations', function (Blueprint $table) {
            $table->id();
            $table->string('external_id', 60)->nullable()->unique();
            $table->foreignId('model_id')->constrained('vehicle_models')->restrictOnDelete();
            $table->string('label');
            $table->string('code', 50)->nullable();
            $table->unsignedSmallInteger('year_from')->nullable();
            $table->unsignedSmallInteger('year_to')->nullable();
            $table->string('market', 50)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('vehicle_engines', function (Blueprint $table) {
            $table->id();
            $table->string('external_id', 60)->nullable()->unique();
            $table->foreignId('model_id')->constrained('vehicle_models')->restrictOnDelete();
            $table->foreignId('generation_id')->nullable()->constrained('vehicle_generations')->nullOnDelete();
            $table->string('label');
            $table->string('designation')->nullable();
            $table->string('engine_code', 50)->nullable();
            $table->unsignedSmallInteger('displacement_cc')->nullable();
            $table->unsignedTinyInteger('cylinders')->nullable();
            $table->string('fuel', 50)->nullable();
            $table->unsignedSmallInteger('power_hp')->nullable();
            $table->unsignedSmallInteger('power_kw')->nullable();
            $table->unsignedSmallInteger('torque_nm')->nullable();
            $table->string('gearbox', 50)->nullable();
            $table->unsignedTinyInteger('gears')->nullable();
            $table->unsignedSmallInteger('year_from')->nullable();
            $table->unsignedSmallInteger('year_to')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('compatibility_sources', function (Blueprint $table) {
            $table->id();
            $table->string('external_id', 60)->nullable()->unique();
            $table->string('publisher');
            $table->string('title');
            $table->string('source_type')->nullable();
            $table->string('url', 500)->nullable();
            $table->string('source_date', 30)->nullable();
            $table->string('scope')->nullable();
            $table->string('reliability')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        $common = function (Blueprint $table) {
            $table->id();
            $table->string('external_id', 60)->nullable()->unique();
            $table->foreignId('model_id')->constrained('vehicle_models')->restrictOnDelete();
            $table->foreignId('generation_id')->nullable()->constrained('vehicle_generations')->nullOnDelete();
            $table->foreignId('engine_id')->nullable()->constrained('vehicle_engines')->nullOnDelete();
            $table->foreignId('source_id')->nullable()->constrained('compatibility_sources')->nullOnDelete();
            $table->string('status', 20)->index(); // App\Enums\CompatibilityStatus
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->date('source_checked_on')->nullable();
            $table->text('notes')->nullable();
        };

        Schema::create('tyre_fitments', function (Blueprint $table) use ($common) {
            $common($table);
            $table->string('trim')->nullable();
            $table->string('position', 10)->default('both'); // both | front | rear
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('aspect_ratio')->nullable();
            $table->decimal('rim_diameter', 4, 1);
            $table->string('load_index', 10)->nullable();
            $table->string('speed_index', 3)->nullable();
            $table->boolean('is_xl')->default(false);
            $table->boolean('is_run_flat')->default(false);
            $table->boolean('is_reinforced')->default(false);
            $table->timestamps();
            $table->index(['width', 'aspect_ratio', 'rim_diameter']);
        });

        Schema::create('oil_fitments', function (Blueprint $table) use ($common) {
            $common($table);
            $table->string('engine_label')->nullable();
            $table->string('viscosity', 20)->nullable()->index();
            $table->string('acea', 50)->nullable();
            $table->string('api', 50)->nullable();
            $table->string('oem_approval', 100)->nullable();
            $table->unsignedInteger('capacity_ml')->nullable();
            $table->string('oil_filter_ref', 100)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['oil_fitments', 'tyre_fitments', 'compatibility_sources', 'vehicle_engines', 'vehicle_generations', 'vehicle_models', 'vehicle_makes'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Individually tracked used tyres (§5.4, D-12, D-46). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('used_tyres', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->string('location', 50)->nullable();
            $table->string('status', 30)->index(); // App\Enums\UsedTyreStatus
            $table->foreignId('brand_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('pattern')->nullable();
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('aspect_ratio')->nullable();
            $table->decimal('rim_diameter', 4, 1);
            $table->string('load_index', 10)->nullable();
            $table->string('speed_index', 3)->nullable();
            $table->unsignedTinyInteger('dot_week')->nullable();
            $table->unsignedSmallInteger('dot_year')->nullable();
            $table->unsignedSmallInteger('tread_depth_tenths')->nullable(); // 0.1 mm
            $table->string('origin', 20); // App\Enums\UsedTyreOrigin
            $table->nullableMorphs('origin_document');
            $table->bigInteger('cost_cents')->nullable();
            $table->bigInteger('price_cents')->nullable();
            $table->json('photos')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['width', 'aspect_ratio', 'rim_diameter']);
        });

        Schema::create('used_tyre_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('used_tyre_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inspector_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('checks'); // check key => true when OK
            $table->unsignedSmallInteger('tread_depth_tenths')->nullable();
            $table->boolean('passed');
            $table->text('notes')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('used_tyre_inspections');
        Schema::dropIfExists('used_tyres');
    }
};

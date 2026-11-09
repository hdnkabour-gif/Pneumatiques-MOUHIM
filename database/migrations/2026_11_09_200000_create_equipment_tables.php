<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 7 (§15): workshop equipment, separate from the sales catalogue, with its maintenance history. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->string('category', 30);
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('in_service'); // in_service | broken | retired
            $table->date('purchased_at')->nullable();
            $table->bigInteger('purchase_cost_cents')->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->date('warranty_until')->nullable();
            $table->unsignedSmallInteger('maintenance_every_days')->nullable();
            $table->date('next_maintenance_on')->nullable()->index();
            $table->string('photo')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('equipment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // breakdown | repair | maintenance
            $table->text('description');
            $table->bigInteger('cost_cents')->default(0);
            $table->string('performed_by')->nullable(); // technician or company
            $table->date('occurred_on');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_events');
        Schema::dropIfExists('equipment');
    }
};

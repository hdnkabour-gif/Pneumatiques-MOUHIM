<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Inter-site transfers (D-11) and store replenishment requests (§2, §6.3). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('replenishment_requests', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_site_id')->constrained('sites')->restrictOnDelete();
            $table->string('status', 20)->index();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('replenishment_request_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('replenishment_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
        });

        Schema::create('transfers', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('from_site_id')->constrained('sites')->restrictOnDelete();
            $table->foreignId('to_site_id')->constrained('sites')->restrictOnDelete();
            $table->string('status', 20)->index();
            $table->foreignId('replenishment_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity_sent');
            $table->unsignedInteger('quantity_received')->nullable();
            $table->string('discrepancy_reason')->nullable();
            $table->bigInteger('unit_cost_cents')->nullable();
        });

        Schema::create('transfer_line_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_line_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_lot_id')->constrained('tyre_lots')->restrictOnDelete();
            $table->unsignedInteger('quantity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_line_lots');
        Schema::dropIfExists('transfer_lines');
        Schema::dropIfExists('transfers');
        Schema::dropIfExists('replenishment_request_lines');
        Schema::dropIfExists('replenishment_requests');
    }
};

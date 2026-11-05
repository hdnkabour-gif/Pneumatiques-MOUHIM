<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Central approval module (§4, D-25).
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40)->index(); // App\Enums\ApprovalType
            $table->string('status', 20)->index(); // App\Enums\ApprovalStatus
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason');
            $table->json('payload');
            $table->nullableMorphs('subject');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decided_via', 10)->nullable(); // app | pin
            $table->text('decision_comment')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            // Owner's personal PIN for on-site approval (hashed).
            $table->string('approval_pin')->nullable();
        });

        // Inventory counts (§6.4).
        Schema::create('inventory_counts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('inventory_count_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_count_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->integer('expected_quantity');
            $table->integer('counted_quantity')->nullable();
            $table->unique(['inventory_count_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_count_lines');
        Schema::dropIfExists('inventory_counts');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('approval_pin'));
        Schema::dropIfExists('approval_requests');
    }
};

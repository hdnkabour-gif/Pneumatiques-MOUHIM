<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Phase 4: daily cash register per site (D-29) and expenses with their photo. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->date('business_date');
            $table->bigInteger('opening_float_cents')->default(0);
            $table->bigInteger('expected_cents')->nullable();
            $table->bigInteger('counted_cents')->nullable();
            $table->bigInteger('difference_cents')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'business_date']);
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->date('business_date')->index();
            $table->string('category', 30);
            $table->bigInteger('amount_cents');
            $table->boolean('paid_in_cash')->default(true);
            $table->string('description');
            $table->string('photo')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE expenses ADD CONSTRAINT expenses_amount_positive CHECK (amount_cents > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('cash_days');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Monthly sales target of each site, set by the owner (§16.1). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_objectives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->char('month', 7); // 2026-11
            $table->bigInteger('target_cents');
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['site_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_objectives');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['tyre_catalogue_references', 'oil_catalogue_references'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->string('record_type', 20);
                $table->string('brand')->nullable()->index();
                $table->string('model')->nullable()->index();
                $table->string('dimension', 40)->nullable()->index();
                $table->json('specifications')->nullable();
                $table->string('verification_status', 40)->default('to_verify');
                $table->string('source_sheet', 40);
                $table->unsignedInteger('source_row');
                $table->char('unique_key', 64)->unique();
                $table->timestamps();
                $table->index(['record_type', 'brand']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('oil_catalogue_references');
        Schema::dropIfExists('tyre_catalogue_references');
    }
};

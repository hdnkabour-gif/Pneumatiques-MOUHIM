<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->string('logo_path')->nullable();
        });
        Schema::table('products', function (Blueprint $table) {
            $table->string('image_path')->nullable();
        });
        Schema::table('vehicle_makes', function (Blueprint $table) {
            $table->string('logo_path')->nullable();
        });
        Schema::table('vehicle_models', function (Blueprint $table) {
            $table->string('image_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_models', fn (Blueprint $table) => $table->dropColumn('image_path'));
        Schema::table('vehicle_makes', fn (Blueprint $table) => $table->dropColumn('logo_path'));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('image_path'));
        Schema::table('brands', fn (Blueprint $table) => $table->dropColumn('logo_path'));
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issued_invoice_documents', function (Blueprint $table) {
            $table->foreignId('sale_id')->primary()->constrained('sales')->restrictOnDelete();
            $table->longText('pdf_base64');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issued_invoice_documents');
    }
};

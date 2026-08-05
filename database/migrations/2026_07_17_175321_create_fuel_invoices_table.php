<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('fuel_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('image_path');
            $table->decimal('total_amount_paid', 8, 2)->nullable();
            $table->decimal('fuel_volume_units', 8, 2)->nullable();
            $table->string('fuel_type')->nullable();
            $table->timestamp('invoice_date')->nullable();
            $table->string('status')->default('pending'); // pending, processing, completed, failed
            $table->text('ocr_raw_text')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fuel_invoices');
    }
};

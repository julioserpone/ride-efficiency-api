<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('energy_invoices', function (Blueprint $table): void {
            $table->string('ocr_provider', 40)->default('azure');
            $table->text('ocr_request_data')->nullable();
            $table->text('ocr_response_data')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('energy_invoices', function (Blueprint $table): void {
            $table->dropColumn(['ocr_provider', 'ocr_request_data', 'ocr_response_data']);
        });
    }
};

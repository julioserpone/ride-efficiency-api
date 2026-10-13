<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::rename('fuel_invoices', 'energy_invoices');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::rename('energy_invoices', 'fuel_invoices');
    }
};

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
        Schema::table('fuel_invoices', function (Blueprint $table) {
            // Energy type the receipt replenished. Carries the unit the volume
            // is measured in, so a combustion receipt (gallons/litres) and an
            // electric one (kilowatt-hours) share one storage column.
            $table->string('energy_type')->nullable()->after('fuel_volume_units');

            // Price paid per unit of energy before tax. The unit is implied by
            // `energy_type`, exactly as the gig-economics domain expects.
            $table->decimal('unit_price', 10, 4)->nullable()->after('energy_type');

            // Odometer reading at the moment of replenishment, used to derive
            // the distance covered since the previous purchase.
            $table->unsignedInteger('odometer')->nullable()->after('unit_price');

            $table->string('vendor_tax_id')->nullable()->after('odometer');
            $table->string('vendor_name')->nullable()->after('vendor_tax_id');

            // Tax rate applied to the subtotal, in percentage points.
            $table->decimal('tax_rate', 5, 2)->nullable()->after('vendor_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fuel_invoices', function (Blueprint $table) {
            $table->dropColumn([
                'energy_type',
                'unit_price',
                'odometer',
                'vendor_tax_id',
                'vendor_name',
                'tax_rate',
            ]);
        });
    }
};

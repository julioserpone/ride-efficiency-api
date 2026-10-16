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
        Schema::table('energy_invoices', function (Blueprint $table) {
            $table->string('vendor_address')->nullable()->after('vendor_name');
            $table->string('station_name')->nullable()->after('vendor_address');
            $table->string('station_brand')->nullable()->after('station_name');
            $table->string('customer_name')->nullable()->after('station_brand');
            $table->string('customer_tax_id', 32)->nullable()->after('customer_name');
            $table->string('customer_email')->nullable()->after('customer_tax_id');
            $table->string('payment_method', 80)->nullable()->after('customer_email');
            $table->string('vehicle_plate', 32)->nullable()->after('payment_method');
            $table->string('invoice_number', 64)->nullable()->after('vehicle_plate');
            $table->string('authorization_number', 64)->nullable()->after('invoice_number');
            $table->string('product_description')->nullable()->after('authorization_number');
            $table->string('item_code', 64)->nullable()->after('product_description');
            $table->decimal('subtotal_amount', 10, 2)->nullable()->after('item_code');
            $table->decimal('tax_amount', 10, 2)->nullable()->after('subtotal_amount');
            $table->decimal('unit_price_without_subsidy', 10, 4)->nullable()->after('tax_amount');
            $table->decimal('subsidy_amount', 10, 2)->nullable()->after('unit_price_without_subsidy');
            $table->decimal('total_amount_without_subsidy', 10, 2)->nullable()->after('subsidy_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('energy_invoices', function (Blueprint $table) {
            $table->dropColumn([
                'vendor_address',
                'station_name',
                'station_brand',
                'customer_name',
                'customer_tax_id',
                'customer_email',
                'payment_method',
                'vehicle_plate',
                'invoice_number',
                'authorization_number',
                'product_description',
                'item_code',
                'subtotal_amount',
                'tax_amount',
                'unit_price_without_subsidy',
                'subsidy_amount',
                'total_amount_without_subsidy',
            ]);
        });
    }
};

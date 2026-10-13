<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add energy details to energy invoices.
     */
    public function up(): void
    {
        Schema::table('energy_invoices', function (Blueprint $table) {
            $table->foreignId('country_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->foreignId('energy_product_id')->nullable()->after('fuel_type')->constrained()->nullOnDelete();
            $table->decimal('energy_amount', 10, 3)->nullable()->after('energy_product_id');
            $table->string('energy_unit', 16)->nullable()->after('energy_amount');
            $table->string('currency_code', 3)->nullable()->after('energy_unit');
            $table->index(['user_id', 'energy_type', 'energy_unit', 'status']);
        });

        DB::table('energy_invoices')->orderBy('id')->chunkById(500, function ($invoices): void {
            foreach ($invoices as $invoice) {
                $countryId = $invoice->country_id
                    ?? DB::table('users')->where('id', $invoice->user_id)->value('country_id');
                $country = $countryId === null
                    ? null
                    : DB::table('countries')->where('id', $countryId)->first(['id', 'code', 'currency_code']);
                $energyUnit = $invoice->energy_unit;

                if ($energyUnit === null && $invoice->energy_type === 'electric') {
                    $energyUnit = 'kwh';
                } elseif ($energyUnit === null && $country !== null) {
                    $energyUnit = $country->code === 'US' ? 'gallons' : 'litres';
                }

                DB::table('energy_invoices')->where('id', $invoice->id)->update([
                    'country_id' => $invoice->country_id ?? $country?->id,
                    'energy_amount' => $invoice->energy_amount ?? $invoice->fuel_volume_units,
                    'energy_unit' => $energyUnit,
                    'currency_code' => $invoice->currency_code ?? $country?->currency_code,
                ]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('energy_invoices', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'energy_type', 'energy_unit', 'status']);
            $table->dropConstrainedForeignId('energy_product_id');
            $table->dropConstrainedForeignId('country_id');
            $table->dropColumn(['energy_amount', 'energy_unit', 'currency_code']);
        });
    }
};

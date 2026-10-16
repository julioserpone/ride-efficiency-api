<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tax_id', 64)->nullable();
            $table->string('tax_id_normalized', 64)->nullable();
            $table->string('legal_name')->nullable();
            $table->string('commercial_name')->nullable();
            $table->string('address')->nullable();
            $table->timestamps();
            $table->unique(['country_id', 'tax_id_normalized']);
            $table->index('legal_name');
            $table->index('commercial_name');
        });

        Schema::table('energy_invoices', function (Blueprint $table) {
            $table->foreignId('vendor_id')->nullable()->after('country_id')->constrained()->nullOnDelete();
        });

        Schema::create('energy_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('energy_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('energy_product_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->string('product_description')->nullable();
            $table->string('item_code', 64)->nullable();
            $table->decimal('quantity', 12, 4)->nullable();
            $table->string('quantity_unit', 16)->nullable();
            $table->decimal('unit_price', 10, 4)->nullable();
            $table->decimal('unit_price_without_subsidy', 10, 4)->nullable();
            $table->decimal('subsidy_amount', 10, 2)->nullable();
            $table->decimal('subtotal_amount', 10, 2)->nullable();
            $table->decimal('tax_rate', 5, 2)->nullable();
            $table->decimal('tax_amount', 10, 2)->nullable();
            $table->decimal('total_amount', 10, 2)->nullable();
            $table->decimal('total_amount_without_subsidy', 10, 2)->nullable();
            $table->timestamps();
            $table->unique(['energy_invoice_id', 'line_number']);
            $table->index(['energy_product_id', 'quantity_unit']);
        });

        DB::table('energy_invoices')
            ->whereNotNull('country_id')
            ->whereNotNull('vendor_tax_id')
            ->orderBy('id')
            ->chunkById(500, function ($invoices): void {
                foreach ($invoices as $invoice) {
                    $taxId = trim((string) $invoice->vendor_tax_id);
                    $normalizedTaxId = strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', $taxId));

                    if ($normalizedTaxId === '') {
                        continue;
                    }

                    $now = now();
                    $vendorId = DB::table('vendors')
                        ->where('country_id', $invoice->country_id)
                        ->where('tax_id_normalized', $normalizedTaxId)
                        ->value('id');

                    if ($vendorId === null) {
                        $vendorId = DB::table('vendors')->insertGetId([
                            'country_id' => $invoice->country_id,
                            'tax_id' => $taxId,
                            'tax_id_normalized' => $normalizedTaxId,
                            'legal_name' => $invoice->vendor_name,
                            'commercial_name' => $invoice->station_brand,
                            'address' => $invoice->vendor_address,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }

                    DB::table('energy_invoices')
                        ->where('id', $invoice->id)
                        ->update(['vendor_id' => $vendorId]);
                }
            });

        DB::table('energy_invoices')
            ->where(function ($query): void {
                $query->whereNotNull('product_description')
                    ->orWhereNotNull('item_code')
                    ->orWhereNotNull('energy_amount')
                    ->orWhereNotNull('unit_price')
                    ->orWhereNotNull('subtotal_amount');
            })
            ->orderBy('id')
            ->chunkById(500, function ($invoices): void {
                foreach ($invoices as $invoice) {
                    DB::table('energy_invoice_items')->insert([
                        'energy_invoice_id' => $invoice->id,
                        'energy_product_id' => $invoice->energy_product_id,
                        'line_number' => 1,
                        'product_description' => $invoice->product_description,
                        'item_code' => $invoice->item_code,
                        'quantity' => $invoice->energy_amount,
                        'quantity_unit' => $invoice->energy_unit,
                        'unit_price' => $invoice->unit_price,
                        'unit_price_without_subsidy' => $invoice->unit_price_without_subsidy,
                        'subsidy_amount' => $invoice->subsidy_amount,
                        'subtotal_amount' => $invoice->subtotal_amount,
                        'tax_rate' => $invoice->tax_rate,
                        'tax_amount' => $invoice->tax_amount,
                        'total_amount' => $invoice->total_amount_paid,
                        'total_amount_without_subsidy' => $invoice->total_amount_without_subsidy,
                        'created_at' => $invoice->created_at,
                        'updated_at' => $invoice->updated_at,
                    ]);
                }
            });

        Schema::table('energy_invoices', function (Blueprint $table) {
            $table->dropColumn([
                'product_description',
                'item_code',
                'unit_price_without_subsidy',
                'subsidy_amount',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('energy_invoices', function (Blueprint $table) {
            $table->string('product_description')->nullable();
            $table->string('item_code', 64)->nullable();
            $table->decimal('unit_price_without_subsidy', 10, 4)->nullable();
            $table->decimal('subsidy_amount', 10, 2)->nullable();
        });

        DB::table('energy_invoices')
            ->orderBy('id')
            ->chunkById(500, function ($invoices): void {
                foreach ($invoices as $invoice) {
                    $item = DB::table('energy_invoice_items')
                        ->where('energy_invoice_id', $invoice->id)
                        ->orderBy('line_number')
                        ->first([
                            'product_description',
                            'item_code',
                            'unit_price_without_subsidy',
                            'subsidy_amount',
                        ]);

                    if ($item !== null) {
                        DB::table('energy_invoices')
                            ->where('id', $invoice->id)
                            ->update([
                                'product_description' => $item->product_description,
                                'item_code' => $item->item_code,
                                'unit_price_without_subsidy' => $item->unit_price_without_subsidy,
                                'subsidy_amount' => $item->subsidy_amount,
                            ]);
                    }
                }
            });

        Schema::dropIfExists('energy_invoice_items');

        Schema::table('energy_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vendor_id');
        });

        Schema::dropIfExists('vendors');
    }
};

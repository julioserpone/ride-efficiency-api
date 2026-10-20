<?php

namespace App\Jobs;

use App\Models\EnergyInvoice;
use App\Models\EnergyProduct;
use App\Services\EnergyProductResolver;
use App\Services\OcrProcessingService;
use App\Services\VendorResolver;
use GigEconomics\Domain\Shared\EnergyUnit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessEnergyInvoiceOcrJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying a failed job.
     */
    public int $backoff = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(public readonly EnergyInvoice $energyInvoice) {}

    /**
     * Execute the job.
     */
    public function handle(
        OcrProcessingService $ocrService,
        EnergyProductResolver $productResolver,
        VendorResolver $vendorResolver,
    ): void {
        $this->energyInvoice->update(['status' => 'processing']);

        try {
            $absolutePath = Storage::disk('local')->path($this->energyInvoice->image_path);
            $country = $this->energyInvoice->country ?? $this->energyInvoice->user->country;

            $result = $ocrService->processImage($absolutePath, $country?->code);
            $providerResponse = $result['_provider_response'] ?? null;
            $provider = $result['_provider'] ?? (string) config('ocr.provider', 'azure');
            unset($result['_provider_response'], $result['_provider']);
            $this->energyInvoice->update([
                'ocr_provider' => $provider,
                'ocr_request_data' => [
                    'model' => config('ocr.azure.model', 'prebuilt-receipt'),
                    'content_type' => mime_content_type($absolutePath) ?: 'application/octet-stream',
                ],
                'ocr_response_data' => $providerResponse,
            ]);
            $items = [];
            $itemDescriptions = array_values(array_map(
                static fn (array $item): string => $item['product_description'] ?? '',
                $result['items'],
            ));
            $resolvedProducts = $productResolver->resolveMany($country, $itemDescriptions);

            foreach ($result['items'] as $lineNumber => $item) {
                $product = $resolvedProducts[$lineNumber];
                $items[] = [
                    ...$item,
                    'line_number' => $lineNumber + 1,
                    'energy_product_id' => $product?->id,
                    'quantity_unit' => $item['quantity_unit'] ?? $product?->energy_unit,
                ];
            }

            $productIds = array_values(array_unique(array_filter(
                array_map(
                    static fn (?EnergyProduct $product): ?int => $product?->id,
                    $resolvedProducts,
                ),
                static fn (?int $productId): bool => $productId !== null,
            )));
            $hasCompatibleItems = $items !== []
                && count($productIds) === 1
                && count(array_filter(
                    $items,
                    static fn (array $item): bool => $item['quantity'] !== null
                        && $item['quantity_unit'] !== null
                        && $item['energy_product_id'] !== null,
                )) === count($items)
                && count(array_unique(array_map(
                    static fn (array $item): string => $item['quantity_unit']->value,
                    $items,
                ))) === 1
                && count(array_unique(array_map(
                    static fn (array $item): string => (string) $item['unit_price'],
                    $items,
                ))) === 1;
            $product = $hasCompatibleItems
                ? ($resolvedProducts[0] ?? null)
                : null;
            $energyAmount = $hasCompatibleItems
                ? array_sum(array_column($items, 'quantity'))
                : null;
            $energyUnit = $hasCompatibleItems
                ? $items[0]['quantity_unit']
                : $result['energy_unit'];
            $unitPrice = $hasCompatibleItems
                ? ($items[0]['unit_price'] ?? $result['unit_price'])
                : $result['unit_price'];
            $isElectric = $energyUnit === EnergyUnit::KilowattHours;
            $isEnergyCompatible = $product !== null
                && $energyUnit !== null
                && $product->energy_type->isElectric() === $energyUnit->isElectric();
            $isReadyForUse = $result['raw_text'] !== ''
                && $result['total_amount_paid'] !== null
                && $energyAmount !== null
                && $energyUnit !== null
                && $isEnergyCompatible
                && $unitPrice !== null
                && $country !== null;
            DB::transaction(function () use (
                $isReadyForUse,
                $result,
                $energyAmount,
                $energyUnit,
                $product,
                $unitPrice,
                $vendorResolver,
                $isElectric,
                $country,
                $items,
            ): void {
                $vendor = $vendorResolver->resolve($country, [
                    'tax_id' => $result['vendor_tax_id'],
                    'legal_name' => $result['vendor_name'] ?? $result['station_name'],
                    'commercial_name' => $result['vendor_commercial_name'],
                    'address' => $result['vendor_address'],
                ]);

                $this->energyInvoice->update([
                    'status' => $isReadyForUse ? 'completed' : 'review_required',
                    'ocr_raw_text' => $result['raw_text'],
                    'total_amount_paid' => $result['total_amount_paid'],
                    'energy_amount' => $energyAmount,
                    'energy_unit' => $energyUnit,
                    'energy_product_id' => $product?->id,
                    'energy_type' => $product?->energy_type,
                    'unit_price' => $unitPrice,
                    'tax_rate' => $result['tax_rate'] ?? 0,
                    'vendor_id' => $vendor?->id,
                    'vendor_name' => $result['vendor_name'],
                    'vendor_tax_id' => $result['vendor_tax_id'],
                    'vendor_address' => $result['vendor_address'],
                    'station_name' => $result['station_name'],
                    'station_brand' => $result['station_brand'],
                    'customer_name' => $result['customer_name'],
                    'customer_tax_id' => $result['customer_tax_id'],
                    'customer_email' => $result['customer_email'],
                    'payment_method' => $result['payment_method'],
                    'vehicle_plate' => $result['vehicle_plate'],
                    'invoice_number' => $result['invoice_number'],
                    'authorization_number' => $result['authorization_number'],
                    'subtotal_amount' => $result['subtotal_amount'],
                    'tax_amount' => $result['tax_amount'],
                    'total_amount_without_subsidy' => $result['total_amount_without_subsidy'],
                    'currency_code' => $country?->currency_code,
                    'fuel_volume_units' => $isElectric ? null : $energyAmount,
                    'fuel_type' => $product->name ?? $result['fuel_type'],
                    'invoice_date' => $result['invoice_date'] ?? $this->energyInvoice->invoice_date,
                ]);

                $this->energyInvoice->items()->delete();
                $this->energyInvoice->items()->createMany($items);
            });

            Log::log($isReadyForUse ? 'info' : 'warning', $isReadyForUse
                ? 'Energy invoice OCR completed.'
                : 'Energy invoice OCR requires manual review.', [
                    'energy_invoice_id' => $this->energyInvoice->id,
                    'status' => $isReadyForUse ? 'completed' : 'review_required',
                    'total_amount' => $result['total_amount_paid'],
                    'energy_amount' => $energyAmount,
                    'energy_unit' => $energyUnit?->value,
                    'country_code' => $country?->code,
                ]);
        } catch (\Exception $e) {
            Log::error('Energy invoice OCR processing failed.', [
                'energy_invoice_id' => $this->energyInvoice->id,
                'error' => $e->getMessage(),
            ]);

            $this->energyInvoice->update(['status' => 'failed']);

            $this->fail($e);
        }
    }
}

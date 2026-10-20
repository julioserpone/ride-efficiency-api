<?php

namespace App\Jobs;

use App\Models\EnergyInvoice;
use App\Services\EnergyProductResolver;
use App\Services\OcrProcessingService;
use App\Services\VendorResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ProcessEncodedEnergyInvoiceOcrJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public readonly EnergyInvoice $energyInvoice,
        public readonly string $encodedImage,
    ) {}

    public function handle(
        OcrProcessingService $ocrService,
        EnergyProductResolver $productResolver,
        VendorResolver $vendorResolver,
    ): void {
        try {
            if (str_starts_with($this->encodedImage, 'data:')) {
                $encodedImage = substr($this->encodedImage, (int) strpos($this->encodedImage, ',') + 1);
            } else {
                $encodedImage = $this->encodedImage;
            }

            $bytes = base64_decode($encodedImage, true);

            if ($bytes === false || $bytes === '') {
                throw new RuntimeException('The encoded energy invoice image is invalid.');
            }

            $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
            $extension = match ($mimeType) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'application/pdf' => 'pdf',
                default => throw new RuntimeException('The encoded energy invoice image must be a JPEG, PNG, or PDF.'),
            };
            Storage::disk('local')->put($this->energyInvoice->image_path, $bytes);
            $this->energyInvoice->update(['status' => 'processing']);
        } catch (\Throwable $exception) {
            Log::error('Encoded energy invoice could not be prepared.', [
                'energy_invoice_id' => $this->energyInvoice->id,
                'error' => $exception->getMessage(),
            ]);
            $this->energyInvoice->update(['status' => 'failed']);
            $this->fail($exception);

            return;
        }

        try {
            $absolutePath = Storage::disk('local')->path($this->energyInvoice->image_path);
            $country = $this->energyInvoice->country ?? $this->energyInvoice->user->country;
            $result = $ocrService->processImage($absolutePath, $country?->code);
            $analysis = $result['_provider_response'] ?? null;
            $provider = $result['_provider'] ?? (string) config('ocr.provider', 'azure');
            unset($result['_provider_response'], $result['_provider']);
            $this->energyInvoice->update([
                'ocr_provider' => $provider,
                'ocr_request_data' => ['model' => config('ocr.azure.model', 'prebuilt-receipt'), 'content_type' => $mimeType],
                'ocr_response_data' => $analysis,
            ]);

            $descriptions = array_values(array_map(static fn (array $item): string => $item['product_description'] ?? '', $result['items']));
            $resolvedProducts = $productResolver->resolveMany($country, $descriptions);
            $items = [];

            foreach ($result['items'] as $index => $item) {
                $product = $resolvedProducts[$index];
                $items[] = [...$item, 'line_number' => $index + 1, 'energy_product_id' => $product?->id,
                    'quantity_unit' => $item['quantity_unit'] ?? $product?->energy_unit];
            }

            $compatible = count($items) === 1 && $items[0]['quantity'] !== null && $items[0]['quantity_unit'] !== null;
            $item = $compatible ? $items[0] : null;
            $product = $compatible ? ($resolvedProducts[0] ?? null) : null;
            $unit = $item['quantity_unit'] ?? $result['energy_unit'];
            $quantity = $item['quantity'] ?? $result['energy_amount'];
            $unitPrice = $item['unit_price'] ?? $result['unit_price'];
            $vendor = $vendorResolver->resolve($country, [
                'tax_id' => $result['vendor_tax_id'],
                'legal_name' => $result['vendor_name'],
                'commercial_name' => $result['vendor_commercial_name'],
                'address' => $result['vendor_address'],
            ]);
            $ready = $result['total_amount_paid'] !== null && $quantity !== null && $unit !== null
                && $unitPrice !== null && $country !== null;

            DB::transaction(function () use ($result, $items, $ready, $product, $unit, $quantity, $unitPrice, $vendor, $country): void {
                $this->energyInvoice->update([
                    'status' => $ready ? 'completed' : 'review_required',
                    'ocr_raw_text' => $result['raw_text'],
                    'total_amount_paid' => $result['total_amount_paid'],
                    'energy_amount' => $quantity,
                    'energy_unit' => $unit,
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
                    'fuel_volume_units' => $unit?->isElectric() ? null : $quantity,
                    'fuel_type' => $product !== null ? $product->name : $result['fuel_type'],
                    'invoice_date' => $result['invoice_date'] ?? $this->energyInvoice->invoice_date,
                ]);
                $this->energyInvoice->items()->delete();
                $this->energyInvoice->items()->createMany($items);
            });

            Log::info('Energy invoice image analyzed by the configured provider.', [
                'energy_invoice_id' => $this->energyInvoice->id,
                'status' => $ready ? 'completed' : 'review_required',
            ]);
        } catch (\Throwable $exception) {
            Log::error('Energy invoice processing failed.', [
                'energy_invoice_id' => $this->energyInvoice->id,
                'error' => $exception->getMessage(),
            ]);
            $this->energyInvoice->update(['status' => 'failed']);
            $this->fail($exception);
        }
    }
}

<?php

namespace App\Jobs;

use App\Models\EnergyInvoice;
use App\Services\EnergyProductResolver;
use App\Services\OcrProcessingService;
use GigEconomics\Domain\Shared\EnergyUnit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
    public function handle(OcrProcessingService $ocrService, EnergyProductResolver $productResolver): void
    {
        $this->energyInvoice->update(['status' => 'processing']);

        try {
            $absolutePath = Storage::disk('local')->path($this->energyInvoice->image_path);
            $country = $this->energyInvoice->country ?? $this->energyInvoice->user->country;

            $result = $ocrService->processImage($absolutePath, $country?->code);
            $product = $productResolver->resolve($country, $result['raw_text']);
            $energyUnit = $result['energy_unit'] ?? $product?->energy_unit;
            $isElectric = $energyUnit === EnergyUnit::KilowattHours;
            $isEnergyCompatible = $product !== null
                && $energyUnit !== null
                && $product->energy_type->isElectric() === $energyUnit->isElectric();
            $isReadyForUse = $result['raw_text'] !== ''
                && $result['total_amount_paid'] !== null
                && $result['energy_amount'] !== null
                && $energyUnit !== null
                && $isEnergyCompatible
                && $result['unit_price'] !== null
                && $country !== null;

            $this->energyInvoice->update([
                'status' => $isReadyForUse ? 'completed' : 'review_required',
                'ocr_raw_text' => $result['raw_text'],
                'total_amount_paid' => $result['total_amount_paid'],
                'energy_amount' => $result['energy_amount'],
                'energy_unit' => $energyUnit,
                'energy_product_id' => $product?->id,
                'energy_type' => $product?->energy_type,
                'unit_price' => $result['unit_price'],
                'tax_rate' => $result['tax_rate'] ?? 0,
                'currency_code' => $country?->currency_code,
                'fuel_volume_units' => $isElectric ? null : $result['energy_amount'],
                'fuel_type' => $product->name ?? $result['fuel_type'],
                'invoice_date' => $result['invoice_date'] ?? $this->energyInvoice->invoice_date,
            ]);

            Log::log($isReadyForUse ? 'info' : 'warning', $isReadyForUse
                ? 'Energy invoice OCR completed.'
                : 'Energy invoice OCR requires manual review.', [
                    'energy_invoice_id' => $this->energyInvoice->id,
                    'status' => $isReadyForUse ? 'completed' : 'review_required',
                    'total_amount' => $result['total_amount_paid'],
                    'energy_amount' => $result['energy_amount'],
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

<?php

namespace App\Jobs;

use App\Models\FuelInvoice;
use App\Services\OcrProcessingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessFuelInvoiceOcrJob implements ShouldQueue
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
    public function __construct(public readonly FuelInvoice $fuelInvoice) {}

    /**
     * Execute the job.
     */
    public function handle(OcrProcessingService $ocrService): void
    {
        $this->fuelInvoice->update(['status' => 'processing']);

        try {
            $absolutePath = Storage::disk('local')->path($this->fuelInvoice->image_path);

            $result = $ocrService->processImage($absolutePath);

            $this->fuelInvoice->update([
                'status' => 'completed',
                'ocr_raw_text' => $result['raw_text'],
                'total_amount_paid' => $result['total_amount_paid'],
                'fuel_volume_units' => $result['fuel_volume_units'],
                'fuel_type' => $result['fuel_type'],
                'invoice_date' => $result['invoice_date'],
            ]);

            Log::info('Fuel invoice OCR completed.', [
                'fuel_invoice_id' => $this->fuelInvoice->id,
                'total_amount' => $result['total_amount_paid'],
                'volume' => $result['fuel_volume_units'],
            ]);
        } catch (\Exception $e) {
            Log::error('Fuel invoice OCR processing failed.', [
                'fuel_invoice_id' => $this->fuelInvoice->id,
                'error' => $e->getMessage(),
            ]);

            $this->fuelInvoice->update(['status' => 'failed']);

            $this->fail($e);
        }
    }
}

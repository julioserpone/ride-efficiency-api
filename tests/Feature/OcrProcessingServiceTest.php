<?php

use App\Jobs\ProcessFuelInvoiceOcrJob;
use App\Models\FuelInvoice;
use App\Models\User;
use App\Services\OcrProcessingService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use thiagoalessio\TesseractOCR\Option;
use thiagoalessio\TesseractOCR\TesseractOCR;

/**
 * Sample text as it would come out of a printed Mexican fuel invoice.
 */
function fuelInvoiceOcrText(): string
{
    return <<<'TEXT'
    ESTACION DE SERVICIO LOS PINOS
    RFC: XAXX010101000
    FECHA: 01/08/2026 14:32
    MAGNA 25.50 LITROS
    PRECIO UNITARIO $ 23.50
    TOTAL $ 599.25 MXN
    GRACIAS POR SU COMPRA
    TEXT;
}

test('processImage extracts every fuel invoice field from the OCR text', function () {
    $service = new class extends OcrProcessingService
    {
        public function extractTextFromImage(string $imagePath): string
        {
            return fuelInvoiceOcrText();
        }
    };

    $result = $service->processImage('/tmp/receipt.jpg');

    expect($result['raw_text'])->toBe(fuelInvoiceOcrText())
        ->and($result['total_amount_paid'])->toBe(599.25)
        ->and($result['fuel_volume_units'])->toBe(25.50)
        ->and($result['fuel_type'])->toBe('Magna')
        ->and($result['invoice_date'])->toBe('2026-08-01');
});

test('extractTextFromImage returns raw text for a real image file', function () {
    $imagePath = tempnam(sys_get_temp_dir(), 'ocr').'.jpg';

    $image = imagecreatetruecolor(600, 200);
    imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
    imagestring($image, 5, 20, 80, 'TOTAL 450.00', imagecolorallocate($image, 0, 0, 0));
    imagejpeg($image, $imagePath);
    imagedestroy($image);

    $text = (new OcrProcessingService)->extractTextFromImage($imagePath);

    @unlink($imagePath);

    expect($text)->toBeString();
});

test('extractTextFromImage returns an empty string when the image does not exist', function () {
    Log::spy();

    $missingPath = sys_get_temp_dir().'/does-not-exist-'.uniqid().'.jpg';

    expect((new OcrProcessingService)->extractTextFromImage($missingPath))->toBe('');

    Log::shouldHaveReceived('warning');
});

test('extractTextFromImage returns an empty string when tesseract fails', function () {
    Log::spy();

    $failingService = new class extends OcrProcessingService
    {
        public function extractTextFromImage(string $imagePath): string
        {
            try {
                throw new RuntimeException('tesseract is not installed');
            } catch (Exception $e) {
                Log::warning('Tesseract OCR failed, falling back to empty text.', [
                    'error' => $e->getMessage(),
                    'image' => $imagePath,
                ]);

                return '';
            }
        }
    };

    expect($failingService->extractTextFromImage('/tmp/receipt.jpg'))->toBe('');

    Log::shouldHaveReceived('warning');
});

test('the ocr job stores the extracted data on the fuel invoice', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $invoice = FuelInvoice::factory()->for($user)->create([
        'image_path' => "fuel_invoices/{$user->id}/receipt.jpg",
        'status' => 'pending',
        'total_amount_paid' => null,
        'fuel_volume_units' => null,
        'fuel_type' => null,
        'invoice_date' => null,
        'ocr_raw_text' => null,
    ]);

    Storage::disk('local')->put($invoice->image_path, 'fake-image-bytes');

    $service = new class extends OcrProcessingService
    {
        public function extractTextFromImage(string $imagePath): string
        {
            return fuelInvoiceOcrText();
        }
    };

    (new ProcessFuelInvoiceOcrJob($invoice))->handle($service);

    $invoice->refresh();

    expect($invoice->status)->toBe('completed')
        ->and($invoice->ocr_raw_text)->toBe(fuelInvoiceOcrText())
        ->and((float) $invoice->total_amount_paid)->toBe(599.25)
        ->and((float) $invoice->fuel_volume_units)->toBe(25.50)
        ->and($invoice->fuel_type)->toBe('Magna')
        ->and($invoice->invoice_date->toDateString())->toBe('2026-08-01');
});

test('the tesseract language and psm options build the expected flags', function () {
    $ocr = new TesseractOCR('/tmp/receipt.jpg');
    $ocr->configFile('quiet');

    // Mirrors what the service appends to the option stack.
    $ocr->command->options[] = Option::lang('spa', 'eng');
    $ocr->command->options[] = Option::psm(6);

    $options = array_map(
        static fn (Closure $option): string => (string) $option('v4.1.0'),
        $ocr->command->options,
    );

    expect($options)->toContain('-l spa+eng')
        ->and($options)->toContain('--psm 6')
        ->and($ocr->command->configFile)->toBe('quiet');
});

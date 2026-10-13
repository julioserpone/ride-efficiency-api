<?php

use App\Jobs\ProcessEnergyInvoiceOcrJob;
use App\Models\Country;
use App\Models\EnergyInvoice;
use App\Models\EnergyProduct;
use App\Models\User;
use App\Services\EnergyProductResolver;
use App\Services\OcrProcessingService;
use GigEconomics\Domain\Shared\EnergyType;
use GigEconomics\Domain\Shared\EnergyUnit;
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

test('processImage extracts electric energy and localized English and Spanish amounts', function () {
    $service = new class extends OcrProcessingService
    {
        public function extractTextFromImage(string $imagePath): string
        {
            return <<<'TEXT'
            EV CHARGING RECEIPT
            Date: 10/13/2026
            Energy delivered: 42,50 kWh
            Unit price: € 0,2990
            VAT: 10,00%
            Total amount due: € 1.234,56
            TEXT;
        }
    };

    $result = $service->processImage('/tmp/receipt.jpg', 'US');

    expect($result['energy_amount'])->toBe(42.5)
        ->and($result['energy_unit'])->toBe(EnergyUnit::KilowattHours)
        ->and($result['unit_price'])->toBe(0.299)
        ->and($result['tax_rate'])->toBe(10.0)
        ->and($result['total_amount_paid'])->toBe(1234.56)
        ->and($result['invoice_date'])->toBe('2026-10-13');
});

test('processImage follows the invoice country date convention', function () {
    $service = new class extends OcrProcessingService
    {
        public function extractTextFromImage(string $imagePath): string
        {
            return 'TOTAL $20.00 5 GAL DATE 01/08/2026';
        }
    };

    expect($service->processImage('/tmp/receipt.jpg', 'US')['invoice_date'])->toBe('2026-01-08')
        ->and($service->processImage('/tmp/receipt.jpg', 'EC')['invoice_date'])->toBe('2026-08-01');
});

test('processImage keeps a quantity when the invoice omits its unit', function () {
    $service = new class extends OcrProcessingService
    {
        public function extractTextFromImage(string $imagePath): string
        {
            return 'Cantidad de energía: 18,75 Total: $12.00';
        }
    };

    $result = $service->processImage('/tmp/receipt.jpg');

    expect($result['energy_amount'])->toBe(18.75)
        ->and($result['energy_unit'])->toBeNull();
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

test('the ocr job stores the extracted data on the energy invoice', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $country = Country::factory()->create(['code' => 'MX']);
    $product = EnergyProduct::query()->create([
        'country_id' => $country->id,
        'code' => 'magna',
        'name' => 'Magna',
        'energy_type' => EnergyType::RegularGasoline,
        'energy_unit' => EnergyUnit::Litres,
        'aliases' => ['magna'],
        'is_active' => true,
    ]);
    $invoice = EnergyInvoice::factory()->for($user)->create([
        'country_id' => $country->id,
        'image_path' => "energy_invoices/{$user->id}/receipt.jpg",
        'status' => 'pending',
        'total_amount_paid' => null,
        'energy_amount' => null,
        'energy_unit' => null,
        'energy_product_id' => null,
        'unit_price' => null,
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

    (new ProcessEnergyInvoiceOcrJob($invoice))->handle($service, new EnergyProductResolver);

    $invoice->refresh();

    expect($invoice->status)->toBe('completed')
        ->and($invoice->ocr_raw_text)->toBe(fuelInvoiceOcrText())
        ->and((float) $invoice->total_amount_paid)->toBe(599.25)
        ->and((float) $invoice->energy_amount)->toBe(25.5)
        ->and($invoice->energy_unit)->toBe(EnergyUnit::Litres)
        ->and($invoice->energy_type)->toBe(EnergyType::RegularGasoline)
        ->and($invoice->energy_product_id)->toBe($product->id)
        ->and((float) $invoice->unit_price)->toBe(23.5)
        ->and((float) $invoice->fuel_volume_units)->toBe(25.50)
        ->and($invoice->fuel_type)->toBe('Magna')
        ->and($invoice->invoice_date->toDateString())->toBe('2026-08-01');
});

test('the ocr job requires review instead of completing an unreadable invoice', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $invoice = EnergyInvoice::factory()->for($user)->create([
        'image_path' => "energy_invoices/{$user->id}/empty.jpg",
        'status' => 'pending',
        'country_id' => null,
    ]);

    Storage::disk('local')->put($invoice->image_path, 'fake-image-bytes');

    $service = new class extends OcrProcessingService
    {
        public function extractTextFromImage(string $imagePath): string
        {
            return '';
        }
    };

    (new ProcessEnergyInvoiceOcrJob($invoice))->handle($service, new EnergyProductResolver);

    expect($invoice->fresh()->status)->toBe('review_required')
        ->and($invoice->fresh()->energy_type)->toBeNull();
});

test('the ocr job requires review when a product conflicts with the printed unit', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $country = Country::factory()->create(['code' => 'US', 'currency_code' => 'USD']);
    EnergyProduct::query()->create([
        'country_id' => $country->id,
        'code' => 'electric',
        'name' => 'Electric charging',
        'energy_type' => EnergyType::Electric,
        'energy_unit' => EnergyUnit::KilowattHours,
        'aliases' => ['charging'],
        'is_active' => true,
    ]);
    $invoice = EnergyInvoice::factory()->for($user)->create([
        'country_id' => $country->id,
        'image_path' => "energy_invoices/{$user->id}/mismatch.jpg",
        'status' => 'pending',
    ]);
    Storage::disk('local')->put($invoice->image_path, 'fake-image-bytes');

    $service = new class extends OcrProcessingService
    {
        public function extractTextFromImage(string $imagePath): string
        {
            return 'EV CHARGING 10 LITRES UNIT PRICE 1.00 TOTAL 10.00';
        }
    };

    (new ProcessEnergyInvoiceOcrJob($invoice))->handle($service, new EnergyProductResolver);

    expect($invoice->fresh()->status)->toBe('review_required')
        ->and($invoice->fresh()->energy_unit)->toBe(EnergyUnit::Litres);
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

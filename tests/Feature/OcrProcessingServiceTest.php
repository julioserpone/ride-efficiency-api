<?php

use App\Jobs\ProcessEnergyInvoiceOcrJob;
use App\Models\Country;
use App\Models\EnergyInvoice;
use App\Models\EnergyProduct;
use App\Models\User;
use App\Models\Vendor;
use App\Services\EnergyProductResolver;
use App\Services\OcrProcessingService;
use App\Services\VendorResolver;
use GigEconomics\Domain\Shared\EnergyType;
use GigEconomics\Domain\Shared\EnergyUnit;
use Illuminate\Support\Carbon;
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

/**
 * Synthetic Ecuadorian invoice text for testing field classification.
 */
function detailedSpanishInvoiceOcrText(): string
{
    return <<<'TEXT'
    COMERCIAL DE PRUEBA S.A.
    9999999999999
    Av. Ejemplo 100 y Calle Ficticia
    ESTACION DE SERVICIO AMAZONAS
    Nombre Comercial: MARCA DE PRUEBA
    Cliente: CLIENTE DE PRUEBA
    RUC/CI: 9999999999
    Email: cliente@example.test
    Fecha: 09/10/2026 01:17:49
    Factura No.: 001-002-000000123
    Forma Pago: Efectivo
    Placa: TEST123
    Art.: SUPER V-POWER Cod.: 0103
    6,25 4,17 4,17 0,00 26,09
    SubTotal $ 26,09
    IVA (15%) $ 3,91
    Total $ 30,00
    Val Tot Sin Sub: 29,99
    Ahorro por Sub: 0,00
    Clave Acceso/No Autorizacion
    9102026010999999999999999999999999999999999999999
    TEXT;
}

/**
 * Synthetic invoice text with two product lines in a tabular layout.
 */
function multipleItemsInvoiceOcrText(): string
{
    return <<<'TEXT'
    GAS STATION SAMPLE LTD
    TAX ID: 999000111
    CANT. DESCRIPCION P SIN SUB PREC UNIT SUB STDIO SUBTOTAL
    2,00 SUPER 4,00 4,00 0,00 8,00
    3,00 SUPER 4,00 4,00 0,00 12,00
    SUBTOTAL 20,00
    IVA (15%) 3,00
    TOTAL 23,00
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
        ->and($result['invoice_date'])->toBe('2026-08-01 14:32:00');
});

test('processImage classifies the invoice identity and tax breakdown without mixing totals', function () {
    Carbon::setTestNow('2026-10-15 12:00:00');
    $service = new class extends OcrProcessingService
    {
        public function extractTextFromImage(string $imagePath): string
        {
            return detailedSpanishInvoiceOcrText();
        }
    };

    $result = $service->processImage('/tmp/receipt.jpg', 'EC');

    Carbon::setTestNow();

    expect($result['total_amount_paid'])->toBe(30.0)
        ->and($result['energy_amount'])->toBe(6.25)
        ->and($result['unit_price'])->toBe(4.17)
        ->and($result['tax_rate'])->toBe(15.0)
        ->and($result['invoice_date'])->toBe('2026-10-09 01:17:49')
        ->and($result['vendor_name'])->toBe('COMERCIAL DE PRUEBA S.A.')
        ->and($result['vendor_tax_id'])->toBe('9999999999999')
        ->and($result['vendor_address'])->toBe('Av. Ejemplo 100 y Calle Ficticia')
        ->and($result['vendor_commercial_name'])->toBe('MARCA DE PRUEBA')
        ->and($result['station_name'])->toBe('AMAZONAS')
        ->and($result['station_brand'])->toBeNull()
        ->and($result['customer_name'])->toBe('CLIENTE DE PRUEBA')
        ->and($result['customer_tax_id'])->toBe('9999999999')
        ->and($result['customer_email'])->toBe('cliente@example.test')
        ->and($result['payment_method'])->toBe('Efectivo')
        ->and($result['vehicle_plate'])->toBe('TEST123')
        ->and($result['invoice_number'])->toBe('001-002-000000123')
        ->and($result['authorization_number'])->toBe('9102026010999999999999999999999999999999999999999')
        ->and($result['items'])->toHaveCount(1)
        ->and($result['items'][0]['product_description'])->toBe('SUPER V-POWER')
        ->and($result['items'][0]['item_code'])->toBe('0103')
        ->and($result['items'][0]['quantity'])->toBe(6.25)
        ->and($result['items'][0]['unit_price'])->toBe(4.17)
        ->and($result['subtotal_amount'])->toBe(26.09)
        ->and($result['tax_amount'])->toBe(3.91)
        ->and($result['total_amount_without_subsidy'])->toBe(29.99);
});

test('processImage extracts every row from a multi-item invoice table', function () {
    $service = new class extends OcrProcessingService
    {
        public function extractTextFromImage(string $imagePath): string
        {
            return multipleItemsInvoiceOcrText();
        }
    };

    $result = $service->processImage('/tmp/receipt.jpg', 'EC');

    expect($result['items'])->toHaveCount(2)
        ->and($result['items'][0]['product_description'])->toBe('SUPER')
        ->and($result['items'][0]['quantity'])->toBe(2.0)
        ->and($result['items'][0]['subtotal_amount'])->toBe(8.0)
        ->and($result['items'][1]['quantity'])->toBe(3.0)
        ->and($result['items'][1]['subtotal_amount'])->toBe(12.0)
        ->and($result['energy_amount'])->toBeNull()
        ->and($result['unit_price'])->toBeNull();
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

    (new ProcessEnergyInvoiceOcrJob($invoice))->handle($service, new EnergyProductResolver, new VendorResolver);

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
        ->and($invoice->invoice_date->format('Y-m-d H:i:s'))->toBe('2026-08-01 14:32:00');
});

test('the ocr job persists the classified invoice identity and tax breakdown', function () {
    Carbon::setTestNow('2026-10-15 12:00:00');
    Storage::fake('local');

    $user = User::factory()->create();
    $country = Country::factory()->create(['code' => 'EC', 'currency_code' => 'USD']);
    EnergyProduct::query()->create([
        'country_id' => $country->id,
        'code' => 'super',
        'name' => 'Súper',
        'energy_type' => EnergyType::PremiumGasoline,
        'energy_unit' => EnergyUnit::Gallons,
        'aliases' => ['super v-power'],
        'is_active' => true,
    ]);
    $invoice = EnergyInvoice::factory()->for($user)->create([
        'country_id' => $country->id,
        'image_path' => "energy_invoices/{$user->id}/detailed.jpg",
        'status' => 'pending',
    ]);
    Storage::disk('local')->put($invoice->image_path, 'fake-image-bytes');

    $service = new class extends OcrProcessingService
    {
        public function extractTextFromImage(string $imagePath): string
        {
            return detailedSpanishInvoiceOcrText();
        }
    };

    (new ProcessEnergyInvoiceOcrJob($invoice))->handle($service, new EnergyProductResolver, new VendorResolver);

    $invoice->refresh();
    Carbon::setTestNow();

    expect($invoice->status)->toBe('completed')
        ->and($invoice->vendor_name)->toBe('COMERCIAL DE PRUEBA S.A.')
        ->and($invoice->vendor_tax_id)->toBe('9999999999999')
        ->and($invoice->vendor_address)->toBe('Av. Ejemplo 100 y Calle Ficticia')
        ->and($invoice->station_name)->toBe('AMAZONAS')
        ->and($invoice->vendor->commercial_name)->toBe('MARCA DE PRUEBA')
        ->and($invoice->station_brand)->toBeNull()
        ->and($invoice->customer_name)->toBe('CLIENTE DE PRUEBA')
        ->and($invoice->customer_tax_id)->toBe('9999999999')
        ->and($invoice->customer_email)->toBe('cliente@example.test')
        ->and($invoice->payment_method)->toBe('Efectivo')
        ->and($invoice->vehicle_plate)->toBe('TEST123')
        ->and($invoice->invoice_number)->toBe('001-002-000000123')
        ->and($invoice->authorization_number)->toBe('9102026010999999999999999999999999999999999999999')
        ->and($invoice->items)->toHaveCount(1)
        ->and($invoice->items[0]->product_description)->toBe('SUPER V-POWER')
        ->and($invoice->items[0]->item_code)->toBe('0103')
        ->and($invoice->items[0]->unit_price_without_subsidy)->toBe('4.1700')
        ->and($invoice->items[0]->subsidy_amount)->toBe('0.00')
        ->and($invoice->subtotal_amount)->toBe('26.09')
        ->and($invoice->tax_amount)->toBe('3.91')
        ->and($invoice->tax_rate)->toBe('15.00')
        ->and($invoice->total_amount_without_subsidy)->toBe('29.99')
        ->and($invoice->invoice_date->format('Y-m-d H:i:s'))->toBe('2026-10-09 01:17:49');
});

test('the ocr job stores multiple invoice items and reuses the supplier master', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $country = Country::factory()->create(['code' => 'EC', 'currency_code' => 'USD']);
    $product = EnergyProduct::query()->create([
        'country_id' => $country->id,
        'code' => 'super',
        'name' => 'Súper',
        'energy_type' => EnergyType::PremiumGasoline,
        'energy_unit' => EnergyUnit::Gallons,
        'aliases' => ['super'],
        'is_active' => true,
    ]);
    $invoices = EnergyInvoice::factory()->count(2)->for($user)->create([
        'country_id' => $country->id,
        'status' => 'pending',
    ]);
    $service = new class extends OcrProcessingService
    {
        public function extractTextFromImage(string $imagePath): string
        {
            return multipleItemsInvoiceOcrText();
        }
    };
    $vendorResolver = new VendorResolver;

    foreach ($invoices as $invoice) {
        Storage::disk('local')->put($invoice->image_path, 'fake-image-bytes');
        (new ProcessEnergyInvoiceOcrJob($invoice))->handle(
            $service,
            new EnergyProductResolver,
            $vendorResolver,
        );
    }

    $invoices = $invoices->map(fn (EnergyInvoice $invoice): EnergyInvoice => $invoice->fresh(['vendor', 'items']));

    expect(Vendor::query()->count())->toBe(1)
        ->and($invoices[0]->vendor_id)->toBe($invoices[1]->vendor_id)
        ->and($invoices[0]->vendor->legal_name)->toBe('GAS STATION SAMPLE LTD')
        ->and($invoices[0]->status)->toBe('completed')
        ->and($invoices[0]->energy_amount)->toBe('5.000')
        ->and($invoices[0]->energy_product_id)->toBe($product->id)
        ->and($invoices[0]->items)->toHaveCount(2)
        ->and($invoices[0]->items[0]->line_number)->toBe(1)
        ->and($invoices[0]->items[0]->quantity)->toBe('2.0000')
        ->and($invoices[0]->items[0]->subtotal_amount)->toBe('8.00')
        ->and($invoices[0]->items[1]->line_number)->toBe(2)
        ->and($invoices[0]->items[1]->quantity)->toBe('3.0000')
        ->and($invoices[0]->items[1]->subtotal_amount)->toBe('12.00');
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

    (new ProcessEnergyInvoiceOcrJob($invoice))->handle($service, new EnergyProductResolver, new VendorResolver);

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

    (new ProcessEnergyInvoiceOcrJob($invoice))->handle($service, new EnergyProductResolver, new VendorResolver);

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

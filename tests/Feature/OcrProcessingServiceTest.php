<?php

use App\Jobs\ProcessEnergyInvoiceOcrJob;
use App\Models\Country;
use App\Models\EnergyInvoice;
use App\Models\EnergyProduct;
use App\Models\User;
use App\Services\AzureDocumentIntelligenceClient;
use App\Services\EnergyProductResolver;
use App\Services\OcrProcessingService;
use App\Services\ReceiptAnalysisProviderManager;
use App\Services\ReceiptDataExtractor;
use App\Services\VendorResolver;
use GigEconomics\Domain\Shared\EnergyType;
use GigEconomics\Domain\Shared\EnergyUnit;
use Illuminate\Support\Facades\Storage;

/**
 * Sample text as it would come out of a printed Mexican fuel invoice.
 */
function fuelInvoiceOcrText(): string
{
    return <<<'TEXT'
    SAMPLE FUEL STATION LLC
    TAX ID: 9999
    ADDRESS: 1 Example Road
    ESTACION DE SERVICIO SAMPLE STATION
    CLIENTE: TEST CUSTOMER
    RUC/CI: 9999
    Email: customer@example.test
    Fecha: 2026-09-22 16:25:59
    Factura No.: 001-002-000123
    Forma Pago: TARJETA
    Placa: TEST123
    Art.: SUPER Cod.: 0103
    6.25 SUPER 4.1744 0103 0.00 4.1744 26.09
    SubTotal: 26.09
    IVA (15%): 3.00
    Total: 30.00
    Val Tot Sin Sub: 30.00
    Clave Acceso SRI: 2209202601179990010080017113991234567819
    TEXT;
}

/** @return array<string mixed> */
function syntheticReceiptAnalysis(): array
{
    return [
        'status' => 'succeeded',
        'analyzeResult' => [
            'modelId' => 'prebuilt-receipt',
            'content' => 'SAMPLE FUEL STATION LLC\nTAX ID: 9999\nADDRESS: 1 Example Road\nESTACION DE SERVICIO SAMPLE STATION\nCLIENTE: TEST CUSTOMER\nRUC/CI: 9999\nEmail: customer@example.test\nFecha: 2026-09-22 16:25:59\nFactura No.: 001-002-000123\nForma Pago: TARJETA\nPlaca: TEST123\nArt.: SUPER Cod.: 0103\n6.25 SUPER 4.17 0103 0.00 4.1744 26.09\nSubTotal: 26.09\nIVA (15%): 3.00\nTotal: 30.00\nVal Tot Sin Sub: 30.00\nClave Acceso SRI: 2209202601179990010080017113991234567819',
            'documents' => [[
                'fields' => [
                    'MerchantName' => ['valueString' => 'SAMPLE FUEL STATION LLC'],
                    'MerchantAddress' => ['content' => '1 Example Road'],
                    'Total' => ['valueCurrency' => ['amount' => 30.0]],
                    'Subtotal' => ['valueCurrency' => ['amount' => 26.09]],
                    'TotalTax' => ['valueCurrency' => ['amount' => 3.0]],
                    'TransactionDate' => ['valueDate' => '2026-09-22'],
                    'TransactionTime' => ['valueTime' => '16:25:59'],
                    'Items' => ['valueArray' => [[
                        'content' => '6.25 SUPER 4.17 0103 0.00 4.1744 26.09',
                        'valueObject' => [
                            'Description' => ['valueString' => 'SUPER'],
                            'UnitPrice' => ['valueNumber' => 4.17],
                            'Quantity' => ['valueNumber' => 6.25],
                            'TotalPrice' => ['valueCurrency' => ['amount' => 26.09]],
                        ],
                    ]]],
                    'TaxDetails' => ['valueArray' => [[
                        'content' => 'IVA (15%) $ 3.00',
                        'valueObject' => ['Rate' => ['valueNumber' => 0.15]],
                    ]]],
                ],
            ]],
        ],
    ];
}

/** @return array<string, mixed> */
function multipleItemsReceiptAnalysis(): array
{
    return [
        'analyzeResult' => [
            'content' => 'SAMPLE FUEL STATION LLC\nRUC: 9999999999999\nCANT. DESCRIPCION\n2.00 SUPER 4.00 8.00\n3.00 SUPER 4.00 12.00\nTOTAL 20.00',
            'documents' => [[
                'fields' => [
                    'MerchantName' => ['valueString' => 'SAMPLE FUEL STATION LLC'],
                    'Total' => ['valueCurrency' => ['amount' => 23.0]],
                    'Items' => ['valueArray' => [
                        ['content' => '2.00 SUPER 4.00 8.00', 'valueObject' => [
                            'Description' => ['valueString' => 'SUPER'],
                            'UnitPrice' => ['valueNumber' => 4.0],
                            'Quantity' => ['valueNumber' => 2.0],
                            'TotalPrice' => ['valueCurrency' => ['amount' => 8.0]],
                        ]],
                        ['content' => '3.00 SUPER 4.00 12.00', 'valueObject' => [
                            'Description' => ['valueString' => 'SUPER'],
                            'UnitPrice' => ['valueNumber' => 4.0],
                            'Quantity' => ['valueNumber' => 3.0],
                            'TotalPrice' => ['valueCurrency' => ['amount' => 12.0]],
                        ]],
                    ]],
                ],
            ]],
        ],
    ];
}

test('processImage extracts every fuel invoice field from the OCR text', function () {
    $service = new OcrProcessingService(new ReceiptAnalysisProviderManager(new class extends AzureDocumentIntelligenceClient
    {
        public function analyze(string $imageBytes, string $contentType): array
        {
            return syntheticReceiptAnalysis();
        }
    }), new ReceiptDataExtractor);

    $result = $service->processImage(receiptTestImagePath());

    expect($result['raw_text'])->toBe(syntheticReceiptAnalysis()['analyzeResult']['content'])
        ->and($result['total_amount_paid'])->toBe(30.0)
        ->and($result['energy_amount'])->toBe(6.25)
        ->and($result['fuel_type'])->toBe('SUPER')
        ->and($result['invoice_date'])->toBe('2026-09-22 16:25:59');
});

test('processImage extracts every row from a multi-item invoice table', function () {
    $service = new OcrProcessingService(new ReceiptAnalysisProviderManager(new class extends AzureDocumentIntelligenceClient
    {
        public function analyze(string $imageBytes, string $contentType): array
        {
            return multipleItemsReceiptAnalysis();
        }
    }), new ReceiptDataExtractor);

    $result = $service->processImage(receiptTestImagePath(), 'EC');

    expect($result['items'])->toHaveCount(2)
        ->and($result['items'][0]['product_description'])->toBe('SUPER')
        ->and($result['items'][0]['quantity'])->toBe(2.0)
        ->and($result['items'][0]['subtotal_amount'])->toBe(8.0)
        ->and($result['items'][1]['quantity'])->toBe(3.0)
        ->and($result['items'][1]['subtotal_amount'])->toBe(12.0)
        ->and($result['energy_amount'])->toBe(5.0)
        ->and($result['unit_price'])->toBe(4.6);
});

test('processImage extracts electric energy and localized English and Spanish amounts', function () {
    $service = new OcrProcessingService(new ReceiptAnalysisProviderManager(new class extends AzureDocumentIntelligenceClient
    {
        public function analyze(string $imageBytes, string $contentType): array
        {
            return ['analyzeResult' => ['content' => <<<'TEXT'
            EV CHARGING RECEIPT
            Date: 10/13/2026
            Energy delivered: 42,50 kWh
            Unit price: € 0,2990
            VAT (10,00%): 0,50
            Total amount due: € 1.234,56
            TEXT]];
        }
    }), new ReceiptDataExtractor);

    $result = $service->processImage(receiptTestImagePath(), 'US');

    expect($result['energy_amount'])->toBe(42.5)
        ->and($result['energy_unit'])->toBe(EnergyUnit::KilowattHours)
        ->and($result['unit_price'])->toBe(0.299)
        ->and($result['tax_rate'])->toBe(10.0)
        ->and($result['total_amount_paid'])->toBe(1234.56)
        ->and($result['invoice_date'])->toBe('2026-10-13');
});

test('processImage follows the invoice country date convention', function () {
    $service = new OcrProcessingService(new ReceiptAnalysisProviderManager(new class extends AzureDocumentIntelligenceClient
    {
        public function analyze(string $imageBytes, string $contentType): array
        {
            return ['analyzeResult' => ['content' => 'TOTAL $20.00 5 GAL DATE 01/08/2026']];
        }
    }), new ReceiptDataExtractor);

    expect($service->processImage(receiptTestImagePath(), 'US')['invoice_date'])->toBe('2026-01-08')
        ->and($service->processImage(receiptTestImagePath(), 'EC')['invoice_date'])->toBe('2026-08-01');
});

test('processImage keeps a quantity when the invoice omits its unit', function () {
    $service = new OcrProcessingService(new ReceiptAnalysisProviderManager(new class extends AzureDocumentIntelligenceClient
    {
        public function analyze(string $imageBytes, string $contentType): array
        {
            return ['analyzeResult' => ['content' => 'Cantidad de energía: 18,75 Total: $12.00']];
        }
    }), new ReceiptDataExtractor);

    $result = $service->processImage(receiptTestImagePath());

    expect($result['energy_amount'])->toBe(18.75)
        ->and($result['energy_unit'])->toBeNull();
});

test('the OCR service sends persisted image bytes through the configured provider', function () {
    $imagePath = tempnam(sys_get_temp_dir(), 'receipt');
    file_put_contents($imagePath, 'synthetic-image-bytes');
    $provider = new class extends AzureDocumentIntelligenceClient
    {
        public function analyze(string $imageBytes, string $contentType): array
        {
            expect($imageBytes)->toBe('synthetic-image-bytes');

            return ['analyzeResult' => ['content' => 'TOTAL $450.00']];
        }
    };
    $service = new OcrProcessingService(new ReceiptAnalysisProviderManager($provider), new ReceiptDataExtractor);

    try {
        expect($service->processImage($imagePath)['raw_text'])->toBe('TOTAL $450.00');
    } finally {
        unlink($imagePath);
    }
});

test('the OCR service reports a missing source image', function () {
    $service = new OcrProcessingService(new ReceiptAnalysisProviderManager(new AzureDocumentIntelligenceClient), new ReceiptDataExtractor);

    expect(fn () => $service->processImage(sys_get_temp_dir().'/missing-receipt.jpg'))
        ->toThrow(RuntimeException::class, 'The energy invoice image is not readable.');
});

test('the provider manager rejects unknown configuration instead of selecting a provider silently', function () {
    config(['ocr.provider' => 'unknown-provider']);

    expect(fn () => (new ReceiptAnalysisProviderManager(new AzureDocumentIntelligenceClient))->driver())
        ->toThrow(InvalidArgumentException::class);
});

test('the ocr job stores the extracted data on the energy invoice', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $country = Country::factory()->create(['code' => 'EC']);
    $product = EnergyProduct::query()->create([
        'country_id' => $country->id,
        'code' => 'super',
        'name' => 'Super',
        'energy_type' => EnergyType::PremiumGasoline,
        'energy_unit' => EnergyUnit::Gallons,
        'aliases' => ['super'],
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

    $service = new OcrProcessingService(new ReceiptAnalysisProviderManager(new class extends AzureDocumentIntelligenceClient
    {
        public function analyze(string $imageBytes, string $contentType): array
        {
            return syntheticReceiptAnalysis();
        }
    }), new ReceiptDataExtractor);

    (new ProcessEnergyInvoiceOcrJob($invoice))->handle($service, new EnergyProductResolver, new VendorResolver);

    $invoice->refresh();

    expect($invoice->status)->toBe('completed')
        ->and($invoice->ocr_raw_text)->toBe(syntheticReceiptAnalysis()['analyzeResult']['content'])
        ->and((float) $invoice->total_amount_paid)->toBe(30.0)
        ->and((float) $invoice->energy_amount)->toBe(6.25)
        ->and($invoice->energy_unit)->toBe(EnergyUnit::Gallons)
        ->and($invoice->energy_type)->toBe(EnergyType::PremiumGasoline)
        ->and($invoice->energy_product_id)->toBe($product->id)
        ->and((float) $invoice->unit_price)->toBe(4.17)
        ->and((float) $invoice->fuel_volume_units)->toBe(6.25)
        ->and($invoice->fuel_type)->toBe('Super')
        ->and($invoice->items)->toHaveCount(1)
        ->and($invoice->items[0]->item_code)->toBe('0103')
        ->and((float) $invoice->items[0]->unit_price_without_subsidy)->toBe(4.1744)
        ->and((float) $invoice->items[0]->unit_price)->toBe(4.17)
        ->and((float) $invoice->items[0]->subsidy_amount)->toBe(0.0)
        ->and((float) $invoice->items[0]->subtotal_amount)->toBe(26.09)
        ->and($invoice->ocr_provider)->toBe('azure')
        ->and($invoice->ocr_response_data['analyzeResult']['modelId'])->toBe('prebuilt-receipt')
        ->and($invoice->invoice_date->format('Y-m-d H:i:s'))->toBe('2026-09-22 16:25:59');
});

test('the ocr job stores multiple invoice items and reuses the supplier master', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $country = Country::factory()->create(['code' => 'EC', 'currency_code' => 'USD']);
    $product = EnergyProduct::query()->create([
        'country_id' => $country->id,
        'code' => 'super',
        'name' => 'SAMPLE SUPER',
        'energy_type' => EnergyType::PremiumGasoline,
        'energy_unit' => EnergyUnit::Gallons,
        'aliases' => ['super'],
        'is_active' => true,
    ]);
    $invoices = EnergyInvoice::factory()->count(2)->for($user)->create([
        'country_id' => $country->id,
        'status' => 'pending',
    ]);
    $service = new OcrProcessingService(new ReceiptAnalysisProviderManager(new class extends AzureDocumentIntelligenceClient
    {
        public function analyze(string $imageBytes, string $contentType): array
        {
            return multipleItemsReceiptAnalysis();
        }
    }), new ReceiptDataExtractor);
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

    expect($invoices[0]->status)->toBe('completed')
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

    $service = new OcrProcessingService(new ReceiptAnalysisProviderManager(new class extends AzureDocumentIntelligenceClient
    {
        public function analyze(string $imageBytes, string $contentType): array
        {
            return ['analyzeResult' => ['content' => '']];
        }
    }), new ReceiptDataExtractor);

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

    $service = new OcrProcessingService(new ReceiptAnalysisProviderManager(new class extends AzureDocumentIntelligenceClient
    {
        public function analyze(string $imageBytes, string $contentType): array
        {
            return ['analyzeResult' => ['content' => 'EV CHARGING 10 LITRES UNIT PRICE 1.00 TOTAL 10.00']];
        }
    }), new ReceiptDataExtractor);

    (new ProcessEnergyInvoiceOcrJob($invoice))->handle($service, new EnergyProductResolver, new VendorResolver);

    expect($invoice->fresh()->status)->toBe('review_required')
        ->and($invoice->fresh()->energy_unit)->toBe(EnergyUnit::Litres);
});

test('the configured provider manager resolves Azure', function () {
    $provider = new AzureDocumentIntelligenceClient;
    config(['ocr.provider' => 'azure']);

    expect((new ReceiptAnalysisProviderManager($provider))->driver())->toBe($provider);
});

test('extracts data from a synthetic Azure receipt with Art/Cod format', function () {
    $json = [
        'analyzeResult' => [
            'content' => 'SAMPLE STATION LLC'."\n".'Art.: SUPER V-POWER Cod.: 0103'."\n".'6.25 SUPER V-POWER 4.17 0103 0.00 4.17 26.09'."\n".'TOTAL 30.00',
            'documents' => [[
                'fields' => [
                    'Total' => ['valueCurrency' => ['amount' => 30.0]],
                    'Subtotal' => ['valueCurrency' => ['amount' => 26.09]],
                    'TotalTax' => ['valueCurrency' => ['amount' => 3.91]],
                    'Items' => ['valueArray' => [[
                        'content' => '6.25 SUPER V-POWER 4.17 0103 0.00 4.17 26.09',
                        'valueObject' => ['Description' => ['valueString' => 'SUPER V-POWER']],
                    ]]],
                ],
            ]],
        ],
    ];
    $extractor = new ReceiptDataExtractor;
    $result = $extractor->extract($json, 'EC');

    expect($result['energy_amount'])->toBe(6.25)
        ->and($result['fuel_type'])->toBe('SUPER V-POWER')
        ->and($result['items'])->toHaveCount(1)
        ->and($result['items'][0]['quantity'])->toBe(6.25)
        ->and($result['items'][0]['item_code'])->toBe('0103')
        ->and($result['items'][0]['unit_price'])->toBe(4.17)
        ->and($result['items'][0]['unit_price_without_subsidy'])->toBe(4.17)
        ->and($result['items'][0]['subsidy_amount'])->toBe(0.0)
        ->and($result['items'][0]['subtotal_amount'])->toBe(26.09)
        ->and($result['total_amount_paid'])->toBe(30.0)
        ->and($result['subtotal_amount'])->toBe(26.09)
        ->and($result['tax_amount'])->toBe(3.91);
});

test('extracts data from a synthetic Azure receipt with table format', function () {
    $json = [
        'analyzeResult' => [
            'content' => 'SAMPLE STATION LLC'."\n".'4.801 SUPER 4.17 0103 0.00 4.1652 20.00'."\n".'TOTAL 23.00',
            'documents' => [[
                'fields' => [
                    'Total' => ['valueCurrency' => ['amount' => 23.0]],
                    'Subtotal' => ['valueCurrency' => ['amount' => 20.0]],
                    'TotalTax' => ['valueCurrency' => ['amount' => 3.0]],
                    'Items' => ['valueArray' => [[
                        'content' => '4.801 SUPER 4.17 0103 0.00 4.1652 20.00',
                        'valueObject' => ['Description' => ['valueString' => 'SUPER']],
                    ]]],
                ],
            ]],
        ],
    ];
    $extractor = new ReceiptDataExtractor;
    $result = $extractor->extract($json, 'EC');

    expect($result['energy_amount'])->toBe(4.801)
        ->and($result['fuel_type'])->toBe('SUPER')
        ->and($result['items'])->toHaveCount(1)
        ->and($result['items'][0]['quantity'])->toBe(4.801)
        ->and($result['items'][0]['item_code'])->toBe('0103')
        ->and($result['items'][0]['unit_price'])->toBe(4.17)
        ->and($result['items'][0]['unit_price_without_subsidy'])->toBe(4.1652)
        ->and($result['items'][0]['subsidy_amount'])->toBe(0.0)
        ->and($result['items'][0]['subtotal_amount'])->toBe(20.0)
        ->and($result['total_amount_paid'])->toBe(23.0)
        ->and($result['subtotal_amount'])->toBe(20.0)
        ->and($result['tax_amount'])->toBe(3.0);
});

/** Return a readable synthetic image path for service tests. */
function receiptTestImagePath(): string
{
    static $path;
    if ($path === null) {
        $path = tempnam(sys_get_temp_dir(), 'receipt');
        file_put_contents($path, 'synthetic-image-bytes');
    }

    return $path;
}

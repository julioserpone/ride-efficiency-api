<?php

use App\Jobs\ProcessEncodedEnergyInvoiceOcrJob;
use App\Jobs\ProcessEnergyInvoiceOcrJob;
use App\Models\Country;
use App\Models\EnergyInvoice;
use App\Models\User;
use App\Models\Vendor;
use GigEconomics\Domain\Shared\EnergyType;
use GigEconomics\Domain\Shared\EnergyUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('authenticated user can upload an energy invoice image', function () {
    Storage::fake('local');
    Queue::fake();

    $user = User::factory()->create();
    $file = UploadedFile::fake()->image('receipt.jpg', 400, 600);

    Sanctum::actingAs($user);
    $response = $this->postJson('/api/v1/energy-invoices', [
        'image' => $file,
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure([
            'message',
            'invoice' => ['id', 'user_id', 'image_path', 'status'],
        ])
        ->assertJsonPath('invoice.status', 'pending');

    Storage::disk('local')->assertExists("energy_invoices/{$user->id}/{$file->hashName()}");
    Queue::assertPushed(ProcessEnergyInvoiceOcrJob::class);
});

test('guest cannot upload an energy invoice', function () {
    Storage::fake('local');

    $file = UploadedFile::fake()->image('receipt.jpg');

    $this->postJson('/api/v1/energy-invoices', ['image' => $file])
        ->assertStatus(401);
});

test('energy invoice upload requires either an image file or base64 payload', function () {
    $user = User::factory()->create();

    Sanctum::actingAs($user);
    $this->postJson('/api/v1/energy-invoices', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['image', 'image_base64']);
});

test('authenticated user can upload a base64 image for queued OCR', function () {
    Storage::fake('local');
    Queue::fake();
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/energy-invoices', [
        'image_base64' => 'data:image/jpeg;base64,'.base64_encode("\xFF\xD8\xFF\xE0synthetic-image"),
    ])->assertCreated()
        ->assertJsonPath('invoice.status', 'pending');

    Queue::assertPushed(ProcessEncodedEnergyInvoiceOcrJob::class);
});

test('the previous fuel invoice endpoint is no longer registered', function () {
    $this->getJson('/api/v1/fuel-invoices')->assertNotFound();
});

test('driver can review an electric invoice whose OCR needs correction', function () {
    $user = User::factory()->create();
    $country = Country::factory()->create(['code' => 'US', 'currency_code' => 'USD']);
    $invoice = EnergyInvoice::factory()->for($user)->create([
        'country_id' => null,
        'energy_type' => null,
        'energy_unit' => null,
        'energy_amount' => null,
        'status' => 'review_required',
    ]);
    Sanctum::actingAs($user);

    $this->patchJson("/api/v1/energy-invoices/{$invoice->id}/review", [
        'country_id' => $country->id,
        'energy_type' => EnergyType::Electric->value,
        'energy_unit' => EnergyUnit::KilowattHours->value,
        'energy_amount' => 35.5,
        'unit_price' => 0.27,
        'total_amount_paid' => 9.59,
        'fuel_type' => 'Home charging',
        'vendor_name' => 'Example Supplier LLC',
        'vendor_tax_id' => '999000111',
        'vendor_commercial_name' => 'Example Energy',
        'vendor_address' => '1 Sample Road',
        'station_name' => 'Demo Station',
        'station_brand' => 'Example Fuel',
        'customer_name' => 'Third-Party Customer',
        'customer_tax_id' => '9999999999',
        'customer_email' => 'customer@example.test',
        'vehicle_plate' => 'TEST123',
        'subtotal_amount' => 8.69,
        'tax_amount' => 0.90,
        'items' => [
            [
                'product_description' => 'Electric charging',
                'item_code' => 'EV-01',
                'quantity' => 35.5,
                'quantity_unit' => EnergyUnit::KilowattHours->value,
                'unit_price' => 0.27,
                'subtotal_amount' => 9.59,
            ],
        ],
    ])->assertSuccessful()
        ->assertJsonPath('invoice.status', 'completed')
        ->assertJsonPath('invoice.energy_type', EnergyType::Electric->value)
        ->assertJsonPath('invoice.energy_unit', EnergyUnit::KilowattHours->value)
        ->assertJsonPath('invoice.fuel_volume_units', null)
        ->assertJsonPath('invoice.station_name', 'Demo Station')
        ->assertJsonPath('invoice.station_brand', 'Example Fuel')
        ->assertJsonPath('invoice.vendor.legal_name', 'Example Supplier LLC')
        ->assertJsonPath('invoice.vendor.commercial_name', 'Example Energy')
        ->assertJsonPath('invoice.customer_name', 'Third-Party Customer')
        ->assertJsonPath('invoice.customer_email', 'customer@example.test')
        ->assertJsonPath('invoice.items.0.product_description', 'Electric charging')
        ->assertJsonPath('invoice.items.0.quantity', '35.5000')
        ->assertJsonPath('invoice.items.0.quantity_unit', EnergyUnit::KilowattHours->value);

    $this->assertDatabaseHas('energy_invoices', [
        'id' => $invoice->id,
        'energy_amount' => 35.5,
        'energy_unit' => EnergyUnit::KilowattHours->value,
        'currency_code' => 'USD',
        'status' => 'completed',
        'vendor_tax_id' => '999000111',
        'station_brand' => 'Example Fuel',
        'customer_tax_id' => '9999999999',
        'subtotal_amount' => 8.69,
        'tax_amount' => 0.90,
    ]);
    expect(Vendor::query()->where('tax_id_normalized', '999000111')->count())->toBe(1);
});

test('invoice review validates optional extracted customer email', function () {
    $user = User::factory()->create();
    $country = Country::factory()->create(['code' => 'EC']);
    $invoice = EnergyInvoice::factory()->for($user)->create(['status' => 'review_required']);
    Sanctum::actingAs($user);

    $this->patchJson("/api/v1/energy-invoices/{$invoice->id}/review", [
        'country_id' => $country->id,
        'energy_type' => EnergyType::RegularGasoline->value,
        'energy_unit' => EnergyUnit::Gallons->value,
        'energy_amount' => 10,
        'unit_price' => 2,
        'customer_email' => 'not-an-email',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['customer_email']);
});

test('energy invoice upload rejects invalid file types', function () {
    $user = User::factory()->create();
    $file = UploadedFile::fake()->create('malware.exe', 100, 'application/octet-stream');

    Sanctum::actingAs($user);
    $this->postJson('/api/v1/energy-invoices', ['image' => $file])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['image']);
});

test('user can list their energy invoices', function () {
    $user = User::factory()->create();
    EnergyInvoice::factory()->count(3)->for($user)->create();

    Sanctum::actingAs($user);
    $response = $this->getJson('/api/v1/energy-invoices');

    $response->assertStatus(200)
        ->assertJsonCount(3, 'invoices.data');
});

test('user can retrieve the extracted invoice identity and tax fields', function () {
    $user = User::factory()->create();
    $country = Country::factory()->create(['code' => 'EC']);
    $vendor = Vendor::query()->create([
        'country_id' => $country->id,
        'tax_id' => '999000111',
        'tax_id_normalized' => '999000111',
        'legal_name' => 'Test Fuel Company LLC',
        'commercial_name' => 'Sample Fuel',
        'address' => '100 Example Road',
    ]);
    $invoice = EnergyInvoice::factory()->for($user)->create([
        'country_id' => $country->id,
        'vendor_id' => $vendor->id,
        'vendor_address' => '100 Example Road',
        'station_name' => 'Demo Station',
        'station_brand' => 'Sample Station Brand',
        'customer_name' => 'Test Customer',
        'customer_tax_id' => '9999999999',
        'customer_email' => 'customer@example.test',
        'payment_method' => 'Cash',
        'vehicle_plate' => 'TEST123',
        'invoice_number' => '001-002-000000123',
        'authorization_number' => '1234567890123456789012345678901234567890123456789',
        'subtotal_amount' => 26.09,
        'tax_amount' => 3.91,
        'tax_rate' => 15,
        'total_amount_without_subsidy' => 29.99,
    ]);
    $invoice->items()->create([
        'line_number' => 1,
        'product_description' => 'Premium gasoline',
        'item_code' => '0103',
        'quantity' => 6.25,
        'quantity_unit' => EnergyUnit::Gallons,
        'unit_price' => 4.17,
        'unit_price_without_subsidy' => 4.17,
        'subsidy_amount' => 0,
        'subtotal_amount' => 26.09,
    ]);
    Sanctum::actingAs($user);

    $this->getJson("/api/v1/energy-invoices/{$invoice->id}")
        ->assertSuccessful()
        ->assertJsonPath('invoice.vendor_name', $invoice->vendor_name)
        ->assertJsonPath('invoice.vendor_tax_id', $invoice->vendor_tax_id)
        ->assertJsonPath('invoice.vendor_address', '100 Example Road')
        ->assertJsonPath('invoice.vendor.legal_name', 'Test Fuel Company LLC')
        ->assertJsonPath('invoice.vendor.commercial_name', 'Sample Fuel')
        ->assertJsonPath('invoice.station_name', 'Demo Station')
        ->assertJsonPath('invoice.station_brand', 'Sample Station Brand')
        ->assertJsonPath('invoice.customer_name', 'Test Customer')
        ->assertJsonPath('invoice.customer_tax_id', '9999999999')
        ->assertJsonPath('invoice.customer_email', 'customer@example.test')
        ->assertJsonPath('invoice.payment_method', 'Cash')
        ->assertJsonPath('invoice.vehicle_plate', 'TEST123')
        ->assertJsonPath('invoice.invoice_number', '001-002-000000123')
        ->assertJsonPath('invoice.authorization_number', '1234567890123456789012345678901234567890123456789')
        ->assertJsonPath('invoice.items.0.product_description', 'Premium gasoline')
        ->assertJsonPath('invoice.items.0.item_code', '0103')
        ->assertJsonPath('invoice.items.0.quantity', '6.2500')
        ->assertJsonPath('invoice.items.0.quantity_unit', EnergyUnit::Gallons->value)
        ->assertJsonPath('invoice.subtotal_amount', '26.09')
        ->assertJsonPath('invoice.tax_amount', '3.91')
        ->assertJsonPath('invoice.tax_rate', '15.00')
        ->assertJsonPath('invoice.total_amount_without_subsidy', '29.99');
});

test('user cannot see another user energy invoices', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $invoice = EnergyInvoice::factory()->for($userB)->create();
    Sanctum::actingAs($userA);
    $this->getJson("/api/v1/energy-invoices/{$invoice->id}")
        ->assertStatus(403);
});

test('user can delete their own energy invoice', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $invoice = EnergyInvoice::factory()->for($user)->create([
        'image_path' => "energy_invoices/{$user->id}/test.jpg",
    ]);

    Sanctum::actingAs($user);
    $this->deleteJson("/api/v1/energy-invoices/{$invoice->id}")
        ->assertStatus(200)
        ->assertJsonPath('message', 'Energy invoice deleted successfully.');

    $this->assertDatabaseMissing('energy_invoices', ['id' => $invoice->id]);
});

<?php

use App\Jobs\ProcessEnergyInvoiceOcrJob;
use App\Models\Country;
use App\Models\EnergyInvoice;
use App\Models\User;
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

test('energy invoice upload requires an image file', function () {
    $user = User::factory()->create();

    Sanctum::actingAs($user);
    $this->postJson('/api/v1/energy-invoices', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['image']);
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
    ])->assertSuccessful()
        ->assertJsonPath('invoice.status', 'completed')
        ->assertJsonPath('invoice.energy_type', EnergyType::Electric->value)
        ->assertJsonPath('invoice.energy_unit', EnergyUnit::KilowattHours->value)
        ->assertJsonPath('invoice.fuel_volume_units', null);

    $this->assertDatabaseHas('energy_invoices', [
        'id' => $invoice->id,
        'energy_amount' => 35.5,
        'energy_unit' => EnergyUnit::KilowattHours->value,
        'currency_code' => 'USD',
        'status' => 'completed',
    ]);
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

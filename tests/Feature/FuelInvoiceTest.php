<?php

use App\Models\FuelInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use App\Jobs\ProcessFuelInvoiceOcrJob;

uses(RefreshDatabase::class);

test('authenticated user can upload a fuel invoice image', function () {
    Storage::fake('local');
    Queue::fake();

    $user = User::factory()->create();
    $file = UploadedFile::fake()->image('receipt.jpg', 400, 600);

    $response = $this->actingAs($user)->postJson('/api/v1/fuel-invoices', [
        'image' => $file,
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure([
            'message',
            'invoice' => ['id', 'user_id', 'image_path', 'status'],
        ])
        ->assertJsonPath('invoice.status', 'pending');

    Storage::disk('local')->assertExists("fuel_invoices/{$user->id}/{$file->hashName()}");
    Queue::assertPushed(ProcessFuelInvoiceOcrJob::class);
});

test('guest cannot upload a fuel invoice', function () {
    Storage::fake('local');

    $file = UploadedFile::fake()->image('receipt.jpg');

    $this->postJson('/api/v1/fuel-invoices', ['image' => $file])
        ->assertStatus(401);
});

test('fuel invoice upload requires an image file', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/fuel-invoices', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['image']);
});

test('fuel invoice upload rejects invalid file types', function () {
    $user = User::factory()->create();
    $file = UploadedFile::fake()->create('malware.exe', 100, 'application/octet-stream');

    $this->actingAs($user)->postJson('/api/v1/fuel-invoices', ['image' => $file])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['image']);
});

test('user can list their fuel invoices', function () {
    $user = User::factory()->create();
    FuelInvoice::factory()->count(3)->for($user)->create();

    $response = $this->actingAs($user)->getJson('/api/v1/fuel-invoices');

    $response->assertStatus(200)
        ->assertJsonCount(3, 'invoices.data');
});

test('user cannot see another user fuel invoices', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $invoice = FuelInvoice::factory()->for($userB)->create();

    $this->actingAs($userA)->getJson("/api/v1/fuel-invoices/{$invoice->id}")
        ->assertStatus(403);
});

test('user can delete their own fuel invoice', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $invoice = FuelInvoice::factory()->for($user)->create([
        'image_path' => "fuel_invoices/{$user->id}/test.jpg",
    ]);

    $this->actingAs($user)->deleteJson("/api/v1/fuel-invoices/{$invoice->id}")
        ->assertStatus(200)
        ->assertJsonPath('message', 'Fuel invoice deleted successfully.');

    $this->assertDatabaseMissing('fuel_invoices', ['id' => $invoice->id]);
});

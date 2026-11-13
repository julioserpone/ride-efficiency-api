<?php

use App\Models\Country;
use App\Models\EnergyInvoice;
use App\Models\RideOffer;
use App\Models\User;
use App\Models\Vehicle;
use GigEconomics\Domain\Shared\EnergyType;
use GigEconomics\Domain\Shared\EnergyUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('list and show offers scoped to user', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    Sanctum::actingAs($user);

    $payload = [
        'client_uuid' => (string) Str::uuid(),
        'platform' => 'uber',
        'currency_code' => 'USD',
        'offered_fare' => 20,
        'estimated_distance_km' => 10,
        'received_at' => now()->toIso8601String(),
    ];
    $this->postJson('/api/v1/ride-offers/sync', ['offers' => [$payload]])->assertOk();

    $this->getJson('/api/v1/ride-offers')->assertOk()->assertJsonCount(1, 'offers.data');

    Sanctum::actingAs($other);
    $this->getJson('/api/v1/ride-offers')->assertOk()->assertJsonCount(0, 'offers.data');
});

test('show returns 403 for another users offer', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    Sanctum::actingAs($owner);

    $payload = [
        'client_uuid' => (string) Str::uuid(),
        'platform' => 'uber',
        'currency_code' => 'USD',
        'offered_fare' => 20,
        'estimated_distance_km' => 10,
        'received_at' => now()->toIso8601String(),
    ];
    $this->postJson('/api/v1/ride-offers/sync', ['offers' => [$payload]])->assertOk();
    $id = RideOffer::first()->id;

    Sanctum::actingAs($other);
    $this->getJson("/api/v1/ride-offers/{$id}")->assertForbidden();
});

test('evaluate returns server verdict when vehicle and energy price available', function () {
    $user = User::factory()->create();
    $country = Country::factory()->create(['code' => 'US', 'currency_code' => 'USD', 'depreciation_rate_per_km' => 0.10]);
    $vehicle = Vehicle::factory()->for($user)->for($country)->create([
        'energy_type' => EnergyType::RegularGasoline,
        'energy_unit' => EnergyUnit::Gallons,
        'energy_economy' => 30,
        'commission_rate' => 25,
    ]);
    EnergyInvoice::factory()->for($user)->create([
        'country_id' => $country->id,
        'currency_code' => 'USD',
        'energy_type' => EnergyType::RegularGasoline,
        'energy_unit' => EnergyUnit::Gallons,
        'unit_price' => 3,
        'status' => 'completed',
    ]);
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/ride-offers/evaluate', [
        'platform' => 'uber',
        'currency_code' => 'USD',
        'offered_fare' => 100,
        'estimated_distance_km' => 60,
        'vehicle_id' => $vehicle->id,
    ])->assertOk()
        ->assertJsonPath('result.server_verdict', 'accept');
});

test('daily summary aggregates offers', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $payloads = [
        offerPayloadForSummary('accepted'),
        offerPayloadForSummary('completed'),
        offerPayloadForSummary('rejected'),
    ];

    $this->postJson('/api/v1/ride-offers/sync', ['offers' => $payloads])->assertOk();

    // mark statuses
    RideOffer::where('client_uuid', $payloads[0]['client_uuid'])->update(['status' => 'accepted']);
    RideOffer::where('client_uuid', $payloads[1]['client_uuid'])->update(['status' => 'completed']);
    RideOffer::where('client_uuid', $payloads[2]['client_uuid'])->update(['status' => 'rejected']);

    $this->getJson('/api/v1/ride-offers/summary/daily')
        ->assertOk()
        ->assertJsonPath('summary.offered', 3)
        ->assertJsonPath('summary.accepted', 1)
        ->assertJsonPath('summary.completed', 1)
        ->assertJsonPath('summary.rejected', 1);
});

function offerPayloadForSummary(string $status): array
{
    return [
        'client_uuid' => (string) Str::uuid(),
        'platform' => 'uber',
        'currency_code' => 'USD',
        'offered_fare' => 20,
        'estimated_distance_km' => 10,
        'received_at' => now()->toIso8601String(),
        'status' => $status,
    ];
}

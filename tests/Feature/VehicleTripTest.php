<?php

use App\Models\Country;
use App\Models\EnergyInvoice;
use App\Models\User;
use App\Models\Vehicle;
use GigEconomics\Domain\Shared\EnergyType;
use GigEconomics\Domain\Shared\EnergyUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('a driver can create a vehicle profile for their energy source', function () {
    $user = User::factory()->create();
    $country = Country::factory()->create(['code' => 'US', 'currency_code' => 'USD']);
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/vehicles', [
        'country_id' => $country->id,
        'name' => 'Electric sedan',
        'energy_type' => EnergyType::Electric->value,
        'energy_unit' => EnergyUnit::KilowattHours->value,
        'energy_economy' => 5.5,
    ])->assertCreated()
        ->assertJsonPath('vehicle.energy_type', EnergyType::Electric->value)
        ->assertJsonPath('vehicle.energy_unit', EnergyUnit::KilowattHours->value)
        ->assertJsonPath('vehicle.energy_economy', '5.5000');
});

test('a driver can calculate and persist the profitability of an individual ride', function () {
    $user = User::factory()->create();
    $country = Country::factory()->create([
        'code' => 'US',
        'currency_code' => 'USD',
        'depreciation_rate_per_km' => 0.10,
    ]);
    $vehicle = Vehicle::factory()->for($user)->for($country)->create([
        'energy_type' => EnergyType::RegularGasoline,
        'energy_unit' => EnergyUnit::Gallons,
        'energy_economy' => 30,
    ]);
    Sanctum::actingAs($user);

    $this->postJson("/api/v1/vehicles/{$vehicle->id}/trips", [
        'platform_name' => 'Uber',
        'gross_fare' => 100,
        'distance_km' => 60,
        'deadhead_distance_km' => 10,
        'duration_minutes' => 45,
        'energy_price_per_unit' => 3,
        'other_costs_per_trip' => 5,
    ])->assertCreated()
        ->assertJsonPath('trip.energy_cost', '7.00')
        ->assertJsonPath('trip.commission_cost', '25.00')
        ->assertJsonPath('trip.depreciation_cost', '7.00')
        ->assertJsonPath('trip.other_costs', '5.00')
        ->assertJsonPath('trip.net_profit', '56.00')
        ->assertJsonPath('trip.verdict', 'accept');

    $this->assertDatabaseHas('ride_trips', [
        'user_id' => $user->id,
        'vehicle_id' => $vehicle->id,
        'distance_km' => 60,
        'deadhead_distance_km' => 10,
        'net_profit' => 56,
        'currency_code' => 'USD',
    ]);
});

test('an electric ride uses a kilowatt-hour invoice price and economy', function () {
    $user = User::factory()->create();
    $country = Country::factory()->create([
        'code' => 'US',
        'currency_code' => 'USD',
        'depreciation_rate_per_km' => 0.10,
    ]);
    $vehicle = Vehicle::factory()->for($user)->for($country)->electric()->create();
    EnergyInvoice::factory()->for($user)->create([
        'country_id' => $country->id,
        'currency_code' => 'USD',
        'energy_type' => EnergyType::Electric,
        'energy_unit' => EnergyUnit::KilowattHours,
        'energy_amount' => 45,
        'fuel_volume_units' => null,
        'unit_price' => 0.30,
        'status' => 'completed',
    ]);
    Sanctum::actingAs($user);

    $this->postJson("/api/v1/vehicles/{$vehicle->id}/trips", [
        'platform_name' => 'DiDi',
        'gross_fare' => 20,
        'distance_km' => 10,
    ])->assertCreated()
        ->assertJsonPath('trip.energy_price_per_unit', '0.3000')
        ->assertJsonPath('trip.energy_cost', '0.60')
        ->assertJsonPath('trip.net_profit', '14.40');
});

test('a user cannot calculate a trip with another users vehicle', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $vehicle = Vehicle::factory()->for($owner)->create();
    Sanctum::actingAs($otherUser);

    $this->postJson("/api/v1/vehicles/{$vehicle->id}/trips", [
        'platform_name' => 'Uber',
        'gross_fare' => 20,
        'distance_km' => 10,
        'energy_price_per_unit' => 3,
    ])->assertForbidden();
});

test('a trip without a matching invoice requires an explicit energy price', function () {
    $user = User::factory()->create();
    $vehicle = Vehicle::factory()->for($user)->create();
    Sanctum::actingAs($user);

    $this->postJson("/api/v1/vehicles/{$vehicle->id}/trips", [
        'platform_name' => 'Uber',
        'gross_fare' => 20,
        'distance_km' => 10,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['energy_price_per_unit']);
});

test('a vehicle cannot combine electric energy with a liquid-fuel unit', function () {
    $country = Country::factory()->create();
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/vehicles', [
        'country_id' => $country->id,
        'name' => 'Invalid vehicle',
        'energy_type' => EnergyType::Electric->value,
        'energy_unit' => EnergyUnit::Litres->value,
        'energy_economy' => 5,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['energy_unit']);
});

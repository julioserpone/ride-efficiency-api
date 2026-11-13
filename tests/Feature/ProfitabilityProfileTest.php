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

test('unauthenticated request is rejected', function () {
    $this->getJson('/api/v1/profitability/profile')->assertUnauthorized();
});

test('returns global profile without a vehicle', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/profitability/profile')
        ->assertOk()
        ->assertJsonPath('profile.calculation_version', '1')
        ->assertJsonPath('profile.target_profit_per_km', '0.15')
        ->assertJsonPath('profile.vehicle', null)
        ->assertJsonPath('profile.energy_price_per_unit', null);
});

test('returns vehicle profile with latest energy price and depreciation', function () {
    $user = User::factory()->create();
    $country = Country::factory()->create([
        'code' => 'US',
        'currency_code' => 'USD',
        'currency_symbol' => '$',
        'depreciation_rate_per_km' => 0.10,
    ]);
    $vehicle = Vehicle::factory()->for($user)->for($country)->create([
        'energy_type' => EnergyType::RegularGasoline,
        'energy_unit' => EnergyUnit::Gallons,
        'energy_economy' => 30,
        'commission_rate' => 0.25,
    ]);
    EnergyInvoice::factory()->for($user)->create([
        'country_id' => $country->id,
        'currency_code' => 'USD',
        'energy_type' => EnergyType::RegularGasoline,
        'energy_unit' => EnergyUnit::Gallons,
        'unit_price' => 3.50,
        'status' => 'completed',
    ]);
    Sanctum::actingAs($user);

    $this->getJson("/api/v1/profitability/profile?vehicle_id={$vehicle->id}")
        ->assertOk()
        ->assertJsonPath('profile.vehicle.id', $vehicle->id)
        ->assertJsonPath('profile.vehicle.energy_economy', '30.0000')
        ->assertJsonPath('profile.vehicle.commission_rate', '0.25')
        ->assertJsonPath('profile.energy_price_per_unit', '3.5000')
        ->assertJsonPath('profile.country.depreciation_rate_per_km', '0.10')
        ->assertJsonPath('profile.currency_code', 'USD');
});

test('cannot read another users vehicle profile', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $vehicle = Vehicle::factory()->for($owner)->create();
    Sanctum::actingAs($other);

    $this->getJson("/api/v1/profitability/profile?vehicle_id={$vehicle->id}")
        ->assertNotFound();
});

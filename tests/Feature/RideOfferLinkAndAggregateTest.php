<?php

use App\Jobs\AggregateDailyOfferMetricsJob;
use App\Models\Country;
use App\Models\DailyOfferMetric;
use App\Models\RideOffer;
use App\Models\User;
use App\Models\Vehicle;
use GigEconomics\Domain\Shared\EnergyType;
use GigEconomics\Domain\Shared\EnergyUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('completing a trip from an offer links and updates actuals', function () {
    $user = User::factory()->create();
    $country = Country::factory()->create(['code' => 'US', 'currency_code' => 'USD', 'depreciation_rate_per_km' => 0.10]);
    $vehicle = Vehicle::factory()->for($user)->for($country)->create([
        'energy_type' => EnergyType::RegularGasoline,
        'energy_unit' => EnergyUnit::Gallons,
        'energy_economy' => 30,
        'commission_rate' => 25,
    ]);
    Sanctum::actingAs($user);

    $payload = [
        'client_uuid' => (string) Str::uuid(),
        'platform' => 'uber',
        'currency_code' => 'USD',
        'offered_fare' => 20,
        'estimated_distance_km' => 10,
        'received_at' => now()->toIso8601String(),
        'status' => 'accepted',
    ];
    $this->postJson('/api/v1/ride-offers/sync', ['offers' => [$payload]])->assertOk();
    $offer = RideOffer::first();

    $this->postJson("/api/v1/vehicles/{$vehicle->id}/trips", [
        'platform_name' => 'Uber',
        'gross_fare' => 22,
        'distance_km' => 11,
        'energy_price_per_unit' => 3,
        'ride_offer_id' => $offer->id,
    ])->assertCreated();

    $offer->refresh();
    expect($offer->status)->toBe('completed');
    expect($offer->ride_trip_id)->not->toBeNull();
    expect((string) $offer->actual_distance_km)->toBe('11.000');
    expect((string) $offer->actual_fare)->toBe('22.00');
});

test('aggregate job computes daily metrics', function () {
    $user = User::factory()->create();
    RideOffer::create([
        'client_uuid' => (string) Str::uuid(),
        'user_id' => $user->id,
        'platform' => 'uber',
        'currency_code' => 'USD',
        'offered_fare' => 20,
        'estimated_distance_km' => 10,
        'received_at' => now(),
        'status' => 'accepted',
    ]);

    (new AggregateDailyOfferMetricsJob(now()->toDateString()))->handle();

    $metric = DailyOfferMetric::where('user_id', $user->id)->first();
    expect($metric)->not->toBeNull();
    expect($metric->offered)->toBe(1);
    expect($metric->accepted)->toBe(1);
});

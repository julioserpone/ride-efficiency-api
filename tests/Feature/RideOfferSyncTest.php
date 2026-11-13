<?php

use App\Models\Country;
use App\Models\RideOffer;
use App\Models\RideOfferEvent;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function offerPayload(array $overrides = []): array
{
    return array_merge([
        'client_uuid' => (string) Str::uuid(),
        'platform' => 'uber',
        'currency_code' => 'USD',
        'offered_fare' => 20,
        'estimated_distance_km' => 10,
        'received_at' => now()->toIso8601String(),
        'client_verdict' => 'accept',
        'events' => [
            ['event_type' => 'received', 'occurred_at' => now()->toIso8601String()],
        ],
    ], $overrides);
}

test('unauthenticated sync is rejected', function () {
    $this->postJson('/api/v1/ride-offers/sync', ['offers' => [offerPayload()]])
        ->assertUnauthorized();
});

test('syncs a batch and is idempotent on retry', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $payload = offerPayload();
    $this->postJson('/api/v1/ride-offers/sync', ['offers' => [$payload]])
        ->assertOk()
        ->assertJsonPath('synced.0.client_uuid', $payload['client_uuid'])
        ->assertJsonCount(0, 'errors');

    $firstId = RideOffer::where('client_uuid', $payload['client_uuid'])->value('id');
    $this->postJson('/api/v1/ride-offers/sync', ['offers' => [$payload]])
        ->assertOk()
        ->assertJsonPath('synced.0.id', $firstId);

    expect(RideOffer::count())->toBe(1);
    expect(RideOfferEvent::count())->toBe(1);
});

test('partial success reports per-item errors', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $country = Country::factory()->create();
    $vehicle = Vehicle::factory()->for($owner)->for($country)->create();
    Sanctum::actingAs($other);

    $bad = offerPayload(['vehicle_id' => $vehicle->id]);
    $good = offerPayload();

    $this->postJson('/api/v1/ride-offers/sync', ['offers' => [$bad, $good]])
        ->assertOk()
        ->assertJsonCount(1, 'synced')
        ->assertJsonCount(1, 'errors');
});

test('sync dedupes events by type and time', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $now = now()->toIso8601String();
    $payload = offerPayload(['events' => [
        ['event_type' => 'received', 'occurred_at' => $now],
        ['event_type' => 'received', 'occurred_at' => $now],
    ]]);

    $this->postJson('/api/v1/ride-offers/sync', ['offers' => [$payload]])->assertOk();
    expect(RideOfferEvent::count())->toBe(1);
});

test('cannot sync an offer referencing another users vehicle', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $country = Country::factory()->create();
    $vehicle = Vehicle::factory()->for($owner)->for($country)->create();
    Sanctum::actingAs($other);

    $payload = offerPayload(['vehicle_id' => $vehicle->id]);
    $this->postJson('/api/v1/ride-offers/sync', ['offers' => [$payload]])
        ->assertOk()
        ->assertJsonCount(0, 'synced')
        ->assertJsonCount(1, 'errors');
});

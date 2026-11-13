<?php

use App\Jobs\ExpireRideOffersJob;
use App\Models\RideOffer;
use App\Models\RideOfferEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('valid transition accepted -> completed', function () {
    $user = User::factory()->create();
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
    $id = RideOffer::first()->id;

    $this->patchJson("/api/v1/ride-offers/{$id}", ['status' => 'completed'])
        ->assertOk()
        ->assertJsonPath('offer.status', 'completed');
});

test('invalid transition rejected -> completed returns 422', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $payload = [
        'client_uuid' => (string) Str::uuid(),
        'platform' => 'uber',
        'currency_code' => 'USD',
        'offered_fare' => 20,
        'estimated_distance_km' => 10,
        'received_at' => now()->toIso8601String(),
        'status' => 'rejected',
    ];
    $this->postJson('/api/v1/ride-offers/sync', ['offers' => [$payload]])->assertOk();
    $id = RideOffer::first()->id;

    $this->patchJson("/api/v1/ride-offers/{$id}", ['status' => 'completed'])->assertStatus(422);
});

test('sync reports invalid transition as per-item error', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $payload = [
        'client_uuid' => (string) Str::uuid(),
        'platform' => 'uber',
        'currency_code' => 'USD',
        'offered_fare' => 20,
        'estimated_distance_km' => 10,
        'received_at' => now()->toIso8601String(),
        'status' => 'rejected',
    ];
    $this->postJson('/api/v1/ride-offers/sync', ['offers' => [$payload]])->assertOk();
    $id = RideOffer::first()->id;

    $payload['status'] = 'completed';
    $this->postJson('/api/v1/ride-offers/sync', ['offers' => [$payload]])
        ->assertOk()
        ->assertJsonCount(0, 'synced')
        ->assertJsonCount(1, 'errors');
});

test('expiry job marks past-due offers expired', function () {
    $user = User::factory()->create();
    $offer = RideOffer::create([
        'client_uuid' => (string) Str::uuid(),
        'user_id' => $user->id,
        'platform' => 'uber',
        'currency_code' => 'USD',
        'offered_fare' => 20,
        'estimated_distance_km' => 10,
        'received_at' => now()->subHour(),
        'expires_at' => now()->subMinute(),
        'status' => 'received',
    ]);

    (new ExpireRideOffersJob)->handle();

    expect($offer->fresh()->status)->toBe('expired');
    expect(RideOfferEvent::where('ride_offer_id', $offer->id)->where('event_type', 'expired')->count())->toBe(1);
});

<?php

use App\Models\RideOffer;
use App\Models\RideOfferScan;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('persists a scan with its json payloads and confidence', function (): void {
    $user = User::factory()->create();

    $scan = RideOfferScan::factory()->parsed()->for($user)->create([
        'client_extraction' => ['offered_fare' => '3.45', 'engine' => 'ml_kit_text_v2'],
        'extracted' => ['offered_fare' => '3.45'],
        'ocr_tokens' => [['text' => '$3.45', 'left' => 10, 'top' => 20]],
        'reconciliation' => 'confirmed',
    ]);

    $fresh = $scan->fresh();

    expect($fresh->status)->toBe('parsed')
        ->and($fresh->provider)->toBe('tesseract')
        ->and($fresh->confidence)->toBe('0.9200')
        ->and($fresh->client_extraction['engine'])->toBe('ml_kit_text_v2')
        ->and($fresh->ocr_tokens)->toHaveCount(1)
        ->and($fresh->reconciliation)->toBe('confirmed')
        ->and($fresh->user->is($user))->toBeTrue();
});

it('links a scan to the offer it produced', function (): void {
    $user = User::factory()->create();
    $offer = RideOffer::create([
        'client_uuid' => (string) Str::uuid(),
        'user_id' => $user->id,
        'platform' => 'uber',
        'service_type' => 'uberx_priority',
        'service_type_raw' => 'UberX Priority',
        'currency_code' => 'USD',
        'offered_fare' => '3.45',
        'estimated_distance_km' => '7.600',
        'received_at' => now(),
    ]);
    $scan = RideOfferScan::factory()->for($user)->create(['ride_offer_id' => $offer->id]);

    $offer->update(['ride_offer_scan_id' => $scan->id]);

    expect($offer->fresh()->scan->is($scan))->toBeTrue()
        ->and($scan->fresh()->rideOffer->is($offer))->toBeTrue()
        ->and($offer->fresh()->service_type_raw)->toBe('UberX Priority')
        ->and($offer->fresh()->ocr_confidence_by_field)->toBeNull();
});

it('stores the per-field OCR confidence on the offer', function (): void {
    $offer = RideOffer::create([
        'client_uuid' => (string) Str::uuid(),
        'user_id' => User::factory()->create()->id,
        'platform' => 'didi',
        'currency_code' => 'USD',
        'offered_fare' => '1.56',
        'estimated_distance_km' => '5.400',
        'received_at' => now(),
        'ocr_confidence' => '0.8700',
        'ocr_confidence_by_field' => ['offered_fare' => 0.98, 'estimated_distance_km' => 0.81],
    ]);

    expect($offer->fresh()->ocr_confidence_by_field)
        ->toBe(['offered_fare' => 0.98, 'estimated_distance_km' => 0.81]);
});

it('keeps the scan idempotent per user and client uuid', function (): void {
    $user = User::factory()->create();
    $uuid = (string) Str::uuid();

    RideOfferScan::factory()->for($user)->create(['client_uuid' => $uuid]);

    expect(fn () => RideOfferScan::factory()->for($user)->create(['client_uuid' => $uuid]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('deletes scans with the user and clears the offer link on delete', function (): void {
    $user = User::factory()->create();
    $scan = RideOfferScan::factory()->for($user)->create();
    $offer = RideOffer::create([
        'client_uuid' => (string) Str::uuid(),
        'user_id' => $user->id,
        'platform' => 'uber',
        'currency_code' => 'USD',
        'offered_fare' => '3.45',
        'estimated_distance_km' => '7.600',
        'received_at' => now(),
        'ride_offer_scan_id' => $scan->id,
    ]);

    $scan->delete();

    expect($offer->fresh()->ride_offer_scan_id)->toBeNull()
        ->and(RideOfferScan::count())->toBe(0);
});

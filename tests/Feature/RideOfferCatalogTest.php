<?php

use App\Models\User;
use App\Services\Offers\ServiceTypeCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('publishes the canonical catalogue for clients', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v1/ride-offers/catalog')
        ->assertOk()
        ->assertJsonPath('version', '1')
        ->assertJsonPath('catalog.uber.service_types.uberx.label', 'UberX')
        ->assertJsonPath('catalog.didi.service_types.set_your_fare.label', 'Set your fare')
        ->assertJsonPath('availability_types.exclusive', 'Exclusive offer')
        ->assertJsonStructure([
            'catalog' => ['uber' => ['label', 'aliases', 'service_types']],
            'availability_types',
            'version',
        ]);
});

it('requires authentication for the catalogue', function (): void {
    $this->getJson('/api/v1/ride-offers/catalog')->assertUnauthorized();
});

it('exposes every documented uber and didi service type', function (): void {
    $catalog = ServiceTypeCatalog::fromConfig();

    expect($catalog->serviceTypeCodes('uber'))
        ->toContain('uberx', 'uberx_priority', 'uberx_share', 'comfort', 'comfort_electric', 'black', 'black_suv')
        ->toContain('xl', 'pet', 'electric', 'van', 'taxi', 'reserve', 'connect', 'intercity', 'moto', 'auto')
        ->and($catalog->serviceTypeCodes('didi'))
        ->toContain('express', 'taxi', 'set_your_fare', 'moto', 'comfort', 'scheduled')
        ->and($catalog->platformCodes())->toBe(['uber', 'didi', 'indrive', 'lyft', 'custom']);
});

it('normalizes platform and service labels coming from OCR', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/ride-offers/catalog/normalize', [
        'platform' => 'Uber',
        'service_type' => 'UberX Priority',
        'availability_type' => 'Exclusivo',
    ])->assertOk()
        ->assertJsonPath('platform', 'uber')
        ->assertJsonPath('service_type', 'uberx_priority')
        ->assertJsonPath('availability_type', 'exclusive');

    $this->postJson('/api/v1/ride-offers/catalog/normalize', [
        'platform' => 'DiDi',
        'service_type' => 'Express Nuevo',
    ])->assertOk()
        ->assertJsonPath('platform', 'didi')
        ->assertJsonPath('service_type', 'express');

    $this->postJson('/api/v1/ride-offers/catalog/normalize', [
        'platform' => 'didi',
        'service_type' => 'Set your fare',
    ])->assertOk()
        ->assertJsonPath('service_type', 'set_your_fare');
});

it('never invents a service type that is not catalogued', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/ride-offers/catalog/normalize', [
        'platform' => 'uber',
        'service_type' => 'Paseo turístico',
    ])->assertOk()
        ->assertJsonPath('platform', 'uber')
        ->assertJsonPath('service_type', null);
});

it('resolves a service type without an explicit platform', function (): void {
    $catalog = ServiceTypeCatalog::fromConfig();

    expect($catalog->normalizeServiceType('Uber Comfort'))->toBe('comfort')
        ->and($catalog->normalizeServiceType('didi moto'))->toBe('moto')
        ->and($catalog->normalizeServiceType('inDrive Intercity'))->toBe('intercity');
});

it('returns null for unknown platforms instead of a custom match', function (): void {
    $catalog = ServiceTypeCatalog::fromConfig();

    expect($catalog->normalizePlatform('Cabify'))->toBeNull()
        ->and($catalog->normalizePlatform(' inDrive '))->toBe('indrive')
        ->and($catalog->normalizeAvailabilityType('Nada'))->toBeNull();
});

it('detects the didi trip centre screen which is not an offer', function (): void {
    $catalog = ServiceTypeCatalog::fromConfig();

    expect($catalog->isNonOfferScreen('didi', 'Centro de viajes'."\n".'No hay más solicitudes'))
        ->toBeTrue()
        ->and($catalog->isNonOfferScreen('didi', 'Express Nuevo'."\n".'$1.56'))->toBeFalse();
});

it('validates the normalize payload', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/ride-offers/catalog/normalize', [
        'platform' => str_repeat('a', 33),
        'service_type' => str_repeat('b', 65),
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['platform', 'service_type']);
});

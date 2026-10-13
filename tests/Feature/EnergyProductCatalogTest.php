<?php

use App\Models\Country;
use App\Models\User;
use App\Services\EnergyProductResolver;
use Database\Seeders\CountrySeeder;
use Database\Seeders\EnergyProductSeeder;
use GigEconomics\Domain\Shared\EnergyType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('classifies the same regional fuel name according to its country', function () {
    (new CountrySeeder)->run();
    (new EnergyProductSeeder)->run();

    $resolver = new EnergyProductResolver;
    $ecuador = Country::query()->where('code', 'EC')->firstOrFail();
    $colombia = Country::query()->where('code', 'CO')->firstOrFail();

    expect($resolver->resolve($ecuador, 'EXTRA 25,50 LITROS')?->energy_type)
        ->toBe(EnergyType::RegularGasoline)
        ->and($resolver->resolve($colombia, 'EXTRA 25,50 LITROS')?->energy_type)
        ->toBe(EnergyType::PremiumGasoline)
        ->and($resolver->resolve($ecuador, 'GASOLINA SUPER')?->energy_type)
        ->toBe(EnergyType::PremiumGasoline);
});

test('authenticated users can retrieve a country energy product catalog', function () {
    (new CountrySeeder)->run();
    (new EnergyProductSeeder)->run();

    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v1/countries/ec/energy-products')
        ->assertSuccessful()
        ->assertJsonPath('country.code', 'EC')
        ->assertJsonFragment([
            'code' => 'ecopais',
            'name' => 'Ecopaís',
            'energy_type' => EnergyType::RegularGasoline->value,
            'energy_unit' => 'gallons',
        ]);
});

test('authenticated users can retrieve active countries with their database ids', function () {
    $activeCountry = Country::factory()->create([
        'name' => 'Ecuador',
        'code' => 'EC',
        'currency_code' => 'USD',
        'currency_symbol' => '$',
        'is_active' => true,
    ]);
    Country::factory()->create(['name' => 'Inactive country', 'is_active' => false]);
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v1/countries')
        ->assertOk()
        ->assertJsonPath('countries.0.id', $activeCountry->id)
        ->assertJsonPath('countries.0.code', 'EC')
        ->assertJsonPath('countries.0.name', 'Ecuador')
        ->assertJsonPath('countries.0.currency_code', 'USD')
        ->assertJsonPath('countries.0.currency_symbol', '$')
        ->assertJsonCount(1, 'countries');
});

test('country catalog requires authentication', function () {
    $this->getJson('/api/v1/countries')->assertUnauthorized();
});

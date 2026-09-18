<?php

use App\Actions\Shifts\CalculateShiftProfitabilityAction;
use App\Models\Country;
use App\Models\DailyShift;
use App\Models\User;
use Database\Seeders\CountrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('country seeder populates default country settings', function () {
    $this->seed(CountrySeeder::class);

    $this->assertDatabaseHas('countries', ['code' => 'US', 'currency_code' => 'USD', 'depreciation_rate_per_km' => 0.15]);
    $this->assertDatabaseHas('countries', ['code' => 'MX', 'currency_code' => 'MXN', 'depreciation_rate_per_km' => 2.50]);
    $this->assertDatabaseHas('countries', ['code' => 'CO', 'currency_code' => 'COP', 'depreciation_rate_per_km' => 500.00]);
});

test('calculates shift depreciation using user country depreciation rate', function () {
    $countryMx = Country::factory()->create([
        'code' => 'MX',
        'depreciation_rate_per_km' => 2.50,
    ]);

    $user = User::factory()->create([
        'country_id' => $countryMx->id,
    ]);

    $shift = DailyShift::factory()->create([
        'user_id' => $user->id,
        'total_km_gps' => 100.00,
        'applied_fuel_cost' => 50.00,
    ]);

    $shift->earnings()->create([
        'provider_name' => 'Uber',
        'gross_amount' => 500.00,
    ]);

    $action = new CalculateShiftProfitabilityAction;
    $updatedShift = $action->execute($shift);

    // Total earnings = 500.00
    // Applied fuel = 50.00
    // Depreciation = 100 km * 2.50 = 250.00
    // Real net profit = 500 - 50 - 250 = 200.00
    expect((float) $updatedShift->estimated_depreciation)->toBe(250.00);
    expect((float) $updatedShift->real_net_profit)->toBe(200.00);
});

test('falls back to default config rate when user has no country', function () {
    $user = User::factory()->create([
        'country_id' => null,
    ]);

    $shift = DailyShift::factory()->create([
        'user_id' => $user->id,
        'total_km_gps' => 100.00,
        'applied_fuel_cost' => 20.00,
    ]);

    $shift->earnings()->create([
        'provider_name' => 'Uber',
        'gross_amount' => 100.00,
    ]);

    $action = new CalculateShiftProfitabilityAction;
    $updatedShift = $action->execute($shift);

    // Default rate = 0.15
    // Depreciation = 100 * 0.15 = 15.00
    expect((float) $updatedShift->estimated_depreciation)->toBe(15.00);
});

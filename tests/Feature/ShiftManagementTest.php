<?php

use App\Models\DailyShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('authenticated user can start a daily shift with platform earnings', function () {
    $user = User::factory()->create();

    $payload = [
        'shift_date' => '2026-08-05',
        'total_km_gps' => 50.5,
        'total_minutes_connected' => 240,
        'total_trips_completed' => 12,
        'total_offers_scanned' => 30,
        'earnings' => [
            ['provider_name' => 'Uber', 'gross_amount' => 120.50],
            ['provider_name' => 'DiDi', 'gross_amount' => 85.00],
        ],
    ];

    $response = $this->actingAs($user)->postJson(route('api.v1.shifts.store'), $payload);

    $response->assertCreated()
        ->assertJsonPath('message', 'Shift created successfully.')
        ->assertJsonPath('shift.shift_date', '2026-08-05')
        ->assertJsonPath('shift.user_id', $user->id);

    $this->assertDatabaseHas('daily_shifts', [
        'user_id' => $user->id,
        'shift_date' => '2026-08-05',
        'total_minutes_connected' => 240,
    ]);

    $this->assertDatabaseHas('daily_earnings', [
        'provider_name' => 'Uber',
        'gross_amount' => 120.50,
    ]);
});

test('calculates profitability correctly on shift closure', function () {
    $user = User::factory()->create();

    $shift = DailyShift::factory()->create([
        'user_id' => $user->id,
        'shift_date' => '2026-08-05',
        'total_km_gps' => 100.00,
        'applied_fuel_cost' => 0.00,
        'estimated_depreciation' => 0.00,
        'real_net_profit' => 0.00,
    ]);

    $shift->earnings()->createMany([
        ['provider_name' => 'Uber', 'gross_amount' => 120.00],
        ['provider_name' => 'DiDi', 'gross_amount' => 80.00],
    ]);

    // Expected: Total Earnings = 200.00
    // Applied Fuel = 35.00
    // Estimated Depreciation = 100.00 * 0.15 = 15.00
    // Expected Real Net Profit = 200 - 35 - 15 = 150.00
    $closePayload = [
        'applied_fuel_cost' => 35.00,
    ];

    $response = $this->actingAs($user)->postJson(route('api.v1.shifts.close', $shift), $closePayload);

    $response->assertOk()
        ->assertJsonPath('shift.applied_fuel_cost', '35.00')
        ->assertJsonPath('shift.estimated_depreciation', '15.00')
        ->assertJsonPath('shift.real_net_profit', '150.00');

    $this->assertDatabaseHas('daily_shifts', [
        'id' => $shift->id,
        'applied_fuel_cost' => 35.00,
        'estimated_depreciation' => 15.00,
        'real_net_profit' => 150.00,
    ]);
});

test('prevents creating duplicate shifts for the same calendar date', function () {
    $user = User::factory()->create();

    DailyShift::factory()->create([
        'user_id' => $user->id,
        'shift_date' => '2026-08-05',
    ]);

    $payload = [
        'shift_date' => '2026-08-05',
    ];

    $response = $this->actingAs($user)->postJson(route('api.v1.shifts.store'), $payload);

    $response->assertStatus(422)
        ->assertJsonPath('message', 'A shift already exists for this date.');
});

test('user can list their recorded shifts with total summary', function () {
    $user = User::factory()->create();

    $shift = DailyShift::factory()->create([
        'user_id' => $user->id,
        'shift_date' => '2026-08-05',
        'real_net_profit' => 125.50,
        'total_km_gps' => 80.00,
        'total_trips_completed' => 10,
    ]);

    $response = $this->actingAs($user)->getJson(route('api.v1.shifts.index'));

    $response->assertOk()
        ->assertJsonPath('summary.total_shifts', 1)
        ->assertJsonPath('summary.total_net_profit', 125.5)
        ->assertJsonPath('summary.total_km_gps', 80)
        ->assertJsonPath('summary.total_trips_completed', 10);
});

test('user cannot view or close another user shift', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $shiftB = DailyShift::factory()->create([
        'user_id' => $userB->id,
    ]);

    $showResponse = $this->actingAs($userA)->getJson(route('api.v1.shifts.show', $shiftB));
    $showResponse->assertStatus(403);

    $closeResponse = $this->actingAs($userA)->postJson(route('api.v1.shifts.close', $shiftB), []);
    $closeResponse->assertStatus(403);
});

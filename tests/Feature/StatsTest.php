<?php

use App\Models\DailyShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

test('weekly stats returns aggregated data for last 8 weeks', function () {
    $user = User::factory()->create();

    DailyShift::factory()->count(5)->for($user)->create([
        'shift_date' => Carbon::now()->subWeek(),
        'real_net_profit' => 200.00,
        'total_km_gps' => 50.00,
        'applied_fuel_cost' => 30.00,
        'total_trips_completed' => 10,
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/stats/weekly');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'weekly' => [
                '*' => [
                    'week_start',
                    'total_shifts',
                    'total_net_profit',
                    'total_km',
                    'total_fuel_cost',
                    'total_trips',
                ],
            ],
        ]);

    expect($response->json('weekly'))->not->toBeEmpty();
});

test('monthly stats returns aggregated data for last 6 months', function () {
    $user = User::factory()->create();

    DailyShift::factory()->count(3)->for($user)->create([
        'shift_date' => Carbon::now()->subMonth(),
        'real_net_profit' => 150.00,
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/stats/monthly');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'monthly' => [
                '*' => ['month_start', 'total_shifts', 'total_net_profit'],
            ],
        ]);
});

test('summary returns global totals, best shift, and worst shift', function () {
    $user = User::factory()->create();

    DailyShift::factory()->for($user)->create(['real_net_profit' => 500.00, 'shift_date' => '2026-07-01']);
    DailyShift::factory()->for($user)->create(['real_net_profit' => 50.00, 'shift_date' => '2026-07-02']);
    DailyShift::factory()->for($user)->create(['real_net_profit' => -10.00, 'shift_date' => '2026-07-03']);

    $response = $this->actingAs($user)->getJson('/api/v1/stats/summary');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'totals' => ['total_shifts', 'total_net_profit', 'total_km', 'avg_daily_profit'],
            'best_shift' => ['id', 'shift_date', 'real_net_profit'],
            'worst_shift' => ['id', 'shift_date', 'real_net_profit'],
            'current_month_profit',
        ]);

    expect((float) $response->json('best_shift.real_net_profit'))->toBe(500.0);
    expect((float) $response->json('worst_shift.real_net_profit'))->toBe(-10.0);
    expect((int) $response->json('totals.total_shifts'))->toBe(3);
});

test('efficiency returns profit per km and per hour ratios', function () {
    $user = User::factory()->create();

    DailyShift::factory()->for($user)->create([
        'real_net_profit' => 300.00,
        'total_km_gps' => 100.00,
        'total_minutes_connected' => 300,
        'applied_fuel_cost' => 50.00,
        'shift_date' => Carbon::now()->toDateString(),
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/stats/efficiency');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'efficiency' => [
                'profit_per_km',
                'profit_per_hour',
                'fuel_cost_per_km',
                'avg_km_per_shift',
                'avg_minutes_per_shift',
            ],
        ]);

    expect((float) $response->json('efficiency.profit_per_km'))->toBe(3.0);
    expect((float) $response->json('efficiency.profit_per_hour'))->toBe(60.0);
});

test('stats endpoints are protected by auth', function () {
    $this->getJson('/api/v1/stats/weekly')->assertStatus(401);
    $this->getJson('/api/v1/stats/monthly')->assertStatus(401);
    $this->getJson('/api/v1/stats/summary')->assertStatus(401);
    $this->getJson('/api/v1/stats/efficiency')->assertStatus(401);
});

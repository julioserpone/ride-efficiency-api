<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;

test('database seeder creates a realistic idempotent 200 dollar work week', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    $user = User::query()->where('email', 'test@example.com')->firstOrFail();
    $shifts = $user->dailyShifts()->with('earnings')->get();
    $dailyGrossEarnings = $shifts->map(
        fn ($shift): float => (float) $shift->earnings->sum('gross_amount'),
    );

    expect($shifts)->toHaveCount(5)
        ->and($dailyGrossEarnings->every(fn (float $amount): bool => $amount >= 30 && $amount <= 50))->toBeTrue()
        ->and($dailyGrossEarnings->sum())->toBe(200.0);
});

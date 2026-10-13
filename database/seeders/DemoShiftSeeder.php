<?php

namespace Database\Seeders;

use App\Models\DailyShift;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class DemoShiftSeeder extends Seeder
{
    /**
     * Seed a realistic working week for the demo account.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('The demo shift seeder is only available in local and testing environments.');
        }

        $user = User::query()->where('email', 'test@example.com')->firstOrFail();
        $weekStart = now()->startOfWeek()->subWeek();
        $shifts = [
            ['km' => 62.0, 'minutes' => 240, 'trips' => 8, 'offers' => 17, 'fuel' => 5.00, 'earnings' => ['Uber' => 35.00]],
            ['km' => 74.0, 'minutes' => 285, 'trips' => 10, 'offers' => 22, 'fuel' => 6.00, 'earnings' => ['Uber' => 30.00, 'DiDi' => 12.00]],
            ['km' => 58.0, 'minutes' => 210, 'trips' => 7, 'offers' => 15, 'fuel' => 4.50, 'earnings' => ['Uber' => 38.00]],
            ['km' => 82.0, 'minutes' => 300, 'trips' => 11, 'offers' => 25, 'fuel' => 7.00, 'earnings' => ['Uber' => 25.00, 'DiDi' => 20.00]],
            ['km' => 67.0, 'minutes' => 255, 'trips' => 9, 'offers' => 19, 'fuel' => 5.50, 'earnings' => ['Uber' => 40.00]],
        ];

        foreach ($shifts as $day => $shiftData) {
            $shiftDate = $weekStart->copy()->addDays($day)->toDateString();
            $grossEarnings = array_sum($shiftData['earnings']);
            $depreciation = round($shiftData['km'] * 0.15, 2);

            $shift = DailyShift::query()->updateOrCreate(
                ['user_id' => $user->id, 'shift_date' => $shiftDate],
                [
                    'total_km_gps' => $shiftData['km'],
                    'total_minutes_connected' => $shiftData['minutes'],
                    'total_trips_completed' => $shiftData['trips'],
                    'total_offers_scanned' => $shiftData['offers'],
                    'applied_fuel_cost' => $shiftData['fuel'],
                    'estimated_depreciation' => $depreciation,
                    'real_net_profit' => round($grossEarnings - $shiftData['fuel'] - $depreciation, 2),
                ],
            );

            foreach ($shiftData['earnings'] as $provider => $grossAmount) {
                $shift->earnings()->updateOrCreate(
                    ['provider_name' => $provider],
                    ['gross_amount' => $grossAmount],
                );
            }
        }
    }
}

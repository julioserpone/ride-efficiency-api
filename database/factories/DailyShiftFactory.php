<?php

namespace Database\Factories;

use App\Models\DailyShift;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyShift>
 */
class DailyShiftFactory extends Factory
{
    protected $model = DailyShift::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $km = $this->faker->randomFloat(2, 20, 250);
        $fuelCost = $this->faker->randomFloat(2, 10, 40);
        $depreciation = round($km * 0.15, 2);

        return [
            'user_id' => User::factory(),
            'shift_date' => $this->faker->unique()->date(),
            'total_km_gps' => $km,
            'total_minutes_connected' => $this->faker->numberBetween(120, 600),
            'total_trips_completed' => $this->faker->numberBetween(5, 25),
            'total_offers_scanned' => $this->faker->numberBetween(10, 50),
            'applied_fuel_cost' => $fuelCost,
            'estimated_depreciation' => $depreciation,
            'real_net_profit' => 0.00,
        ];
    }
}

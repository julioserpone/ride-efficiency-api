<?php

namespace Database\Factories;

use App\Models\DailyEarning;
use App\Models\DailyShift;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyEarning>
 */
class DailyEarningFactory extends Factory
{
    protected $model = DailyEarning::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'daily_shift_id' => DailyShift::factory(),
            'provider_name' => $this->faker->randomElement(['Uber', 'DiDi', 'Lyft', 'Indrive']),
            'gross_amount' => $this->faker->randomFloat(2, 30, 200),
        ];
    }
}

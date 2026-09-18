<?php

namespace Database\Factories;

use App\Models\Country;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Country>
 */
class CountryFactory extends Factory
{
    protected $model = Country::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper($this->faker->unique()->lexify('??')),
            'name' => $this->faker->country(),
            'currency_code' => $this->faker->currencyCode(),
            'currency_symbol' => '$',
            'phone_code' => '+'.$this->faker->numberBetween(1, 999),
            'depreciation_rate_per_km' => $this->faker->randomFloat(2, 0.10, 0.50),
            'is_active' => true,
        ];
    }
}

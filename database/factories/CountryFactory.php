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
        $suffixes = range('A', 'Z');

        return [
            // Reserved-looking codes (QA..QZ) so a random country never collides
            // with the real ISO codes that tests create explicitly (US, EC, MX).
            'code' => 'Q'.$suffixes[$this->faker->unique()->numberBetween(0, 25)],
            'name' => $this->faker->country(),
            'currency_code' => $this->faker->currencyCode(),
            'currency_symbol' => '$',
            'phone_code' => '+'.$this->faker->numberBetween(1, 999),
            'depreciation_rate_per_km' => $this->faker->randomFloat(2, 0.10, 0.50),
            'is_active' => true,
        ];
    }
}

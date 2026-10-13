<?php

namespace Database\Factories;

use App\Models\Country;
use App\Models\User;
use App\Models\Vehicle;
use GigEconomics\Domain\Shared\EnergyType;
use GigEconomics\Domain\Shared\EnergyUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vehicle>
 */
class VehicleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'country_id' => Country::factory(),
            'name' => $this->faker->words(2, true),
            'energy_type' => EnergyType::RegularGasoline,
            'energy_unit' => EnergyUnit::Gallons,
            'energy_economy' => 30.0000,
            'commission_rate' => null,
            'is_active' => true,
        ];
    }

    public function electric(): static
    {
        return $this->state(fn (array $attributes): array => [
            'energy_type' => EnergyType::Electric,
            'energy_unit' => EnergyUnit::KilowattHours,
            'energy_economy' => 5.0000,
        ]);
    }
}

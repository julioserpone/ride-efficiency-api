<?php

namespace Database\Factories;

use App\Models\FuelInvoice;
use App\Models\User;
use GigEconomics\Domain\Shared\EnergyType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FuelInvoice>
 */
class FuelInvoiceFactory extends Factory
{
    protected $model = FuelInvoice::class;

    /**
     * Define the model's default state.
     *
     * The default describes a combustion receipt bought by volume; use
     * {@see self::electric()} for a recharge bought in kilowatt-hours.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $unitPrice = $this->faker->randomFloat(4, 2, 6);
        $volume = $this->faker->randomFloat(2, 10, 40);
        $taxRate = $this->faker->randomFloat(2, 0, 20);
        $subtotal = round($volume * $unitPrice, 2);

        return [
            'user_id' => User::factory(),
            'image_path' => 'invoices/receipt_'.$this->faker->uuid().'.jpg',
            'total_amount_paid' => round($subtotal * (1 + $taxRate / 100), 2),
            'fuel_volume_units' => $volume,
            'fuel_type' => 'Regular',
            'energy_type' => EnergyType::RegularGasoline,
            'unit_price' => $unitPrice,
            'odometer' => $this->faker->numberBetween(1000, 250000),
            'vendor_tax_id' => strtoupper($this->faker->bothify('???########')),
            'vendor_name' => 'Gas Station '.$this->faker->city(),
            'tax_rate' => $taxRate,
            'invoice_date' => $this->faker->dateTimeThisMonth(),
            'status' => 'completed',
            'ocr_raw_text' => 'GAS STATION TOTAL $50.00 REGULAR 20 GALLONS',
        ];
    }

    /**
     * State for a receipt that replenished an electric vehicle.
     *
     * The energy is bought in kilowatt-hours and priced per kilowatt-hour, so
     * the receipt flows into the library's electric code path.
     */
    public function electric(): static
    {
        return $this->state(function (array $attributes): array {
            $unitPrice = $this->faker->randomFloat(4, 0.10, 0.60);
            $kilowattHours = $this->faker->randomFloat(2, 20, 70);
            $taxRate = $this->faker->randomFloat(2, 0, 20);
            $subtotal = round($kilowattHours * $unitPrice, 2);

            return [
                'total_amount_paid' => round($subtotal * (1 + $taxRate / 100), 2),
                'fuel_volume_units' => $kilowattHours,
                'fuel_type' => 'Electric',
                'energy_type' => EnergyType::Electric,
                'unit_price' => $unitPrice,
                'vendor_name' => 'Charging Hub '.$this->faker->city(),
                'tax_rate' => $taxRate,
                'ocr_raw_text' => 'CHARGING HUB TOTAL $18.00 45.00 KWH',
            ];
        });
    }
}

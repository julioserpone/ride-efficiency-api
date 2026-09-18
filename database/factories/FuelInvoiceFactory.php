<?php

namespace Database\Factories;

use App\Models\FuelInvoice;
use App\Models\User;
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
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'image_path' => 'invoices/receipt_'.$this->faker->uuid().'.jpg',
            'total_amount_paid' => $this->faker->randomFloat(2, 20, 80),
            'fuel_volume_units' => $this->faker->randomFloat(2, 10, 40),
            'fuel_type' => 'Regular',
            'invoice_date' => $this->faker->dateTimeThisMonth(),
            'status' => 'completed',
            'ocr_raw_text' => 'GAS STATION TOTAL $50.00 REGULAR 20 GALLONS',
        ];
    }
}

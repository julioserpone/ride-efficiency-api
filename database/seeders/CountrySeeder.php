<?php

namespace Database\Seeders;

use App\Models\Country;
use Illuminate\Database\Seeder;

class CountrySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $countries = [
            [
                'code' => 'US',
                'name' => 'United States',
                'currency_code' => 'USD',
                'currency_symbol' => '$',
                'phone_code' => '+1',
                'depreciation_rate_per_km' => 0.15,
                'is_active' => true,
            ],
            [
                'code' => 'MX',
                'name' => 'Mexico',
                'currency_code' => 'MXN',
                'currency_symbol' => '$',
                'phone_code' => '+52',
                'depreciation_rate_per_km' => 2.50,
                'is_active' => true,
            ],
            [
                'code' => 'CO',
                'name' => 'Colombia',
                'currency_code' => 'COP',
                'currency_symbol' => '$',
                'phone_code' => '+57',
                'depreciation_rate_per_km' => 500.00,
                'is_active' => true,
            ],
        ];

        foreach ($countries as $countryData) {
            Country::updateOrCreate(['code' => $countryData['code']], $countryData);
        }
    }
}

<?php

namespace Database\Seeders;

use App\Models\Country;
use App\Models\EnergyProduct;
use Illuminate\Database\Seeder;

class EnergyProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $products = [
            'US' => [
                ['code' => 'regular_gasoline', 'name' => 'Regular gasoline', 'energy_type' => 'regular_gasoline', 'energy_unit' => 'gallons', 'aliases' => ['regular', 'unleaded', 'regular gasoline']],
                ['code' => 'midgrade_gasoline', 'name' => 'Midgrade gasoline', 'energy_type' => 'premium_gasoline', 'energy_unit' => 'gallons', 'aliases' => ['midgrade', 'mid-grade', 'plus']],
                ['code' => 'premium_gasoline', 'name' => 'Premium gasoline', 'energy_type' => 'premium_gasoline', 'energy_unit' => 'gallons', 'aliases' => ['premium', 'super unleaded']],
                ['code' => 'diesel', 'name' => 'Diesel', 'energy_type' => 'diesel', 'energy_unit' => 'gallons', 'aliases' => ['diesel']],
                ['code' => 'ethanol', 'name' => 'Ethanol blend', 'energy_type' => 'ethanol', 'energy_unit' => 'gallons', 'aliases' => ['e85', 'ethanol']],
                ['code' => 'lpg', 'name' => 'LPG', 'energy_type' => 'lpg', 'energy_unit' => 'gallons', 'aliases' => ['lpg', 'propane']],
                ['code' => 'cng_gge', 'name' => 'CNG (GGE)', 'energy_type' => 'cng', 'energy_unit' => 'gallons', 'aliases' => ['gge', 'gasoline gallon equivalent']],
                ['code' => 'electric', 'name' => 'Electric charging', 'energy_type' => 'electric', 'energy_unit' => 'kwh', 'aliases' => ['kwh', 'kilowatt hour', 'charging', 'charge session']],
            ],
            'MX' => [
                ['code' => 'magna', 'name' => 'Magna', 'energy_type' => 'regular_gasoline', 'energy_unit' => 'litres', 'aliases' => ['magna']],
                ['code' => 'premium', 'name' => 'Premium', 'energy_type' => 'premium_gasoline', 'energy_unit' => 'litres', 'aliases' => ['premium', 'super']],
                ['code' => 'diesel', 'name' => 'Diesel', 'energy_type' => 'diesel', 'energy_unit' => 'litres', 'aliases' => ['diesel']],
                ['code' => 'electric', 'name' => 'Electric charging', 'energy_type' => 'electric', 'energy_unit' => 'kwh', 'aliases' => ['kwh', 'kilowatt hour', 'carga eléctrica', 'electric charging']],
            ],
            'CO' => [
                ['code' => 'corriente', 'name' => 'Corriente', 'energy_type' => 'regular_gasoline', 'energy_unit' => 'litres', 'aliases' => ['corriente', 'gasolina corriente']],
                ['code' => 'extra', 'name' => 'Extra', 'energy_type' => 'premium_gasoline', 'energy_unit' => 'litres', 'aliases' => ['extra', 'gasolina extra']],
                ['code' => 'acpm', 'name' => 'ACPM / Diesel', 'energy_type' => 'diesel', 'energy_unit' => 'litres', 'aliases' => ['acpm', 'diesel']],
                ['code' => 'electric', 'name' => 'Electric charging', 'energy_type' => 'electric', 'energy_unit' => 'kwh', 'aliases' => ['kwh', 'kilowatt hour', 'carga eléctrica', 'electric charging']],
            ],
            'EC' => [
                ['code' => 'extra', 'name' => 'Extra', 'energy_type' => 'regular_gasoline', 'energy_unit' => 'gallons', 'aliases' => ['extra', 'gasolina extra']],
                ['code' => 'ecopais', 'name' => 'Ecopaís', 'energy_type' => 'regular_gasoline', 'energy_unit' => 'gallons', 'aliases' => ['ecopais', 'ecopaís']],
                ['code' => 'super', 'name' => 'Súper', 'energy_type' => 'premium_gasoline', 'energy_unit' => 'gallons', 'aliases' => ['super', 'súper', 'gasolina super', 'gasolina súper']],
                ['code' => 'diesel', 'name' => 'Diesel', 'energy_type' => 'diesel', 'energy_unit' => 'gallons', 'aliases' => ['diesel', 'diésel']],
                ['code' => 'electric', 'name' => 'Electric charging', 'energy_type' => 'electric', 'energy_unit' => 'kwh', 'aliases' => ['kwh', 'kilowatt hour', 'carga eléctrica', 'electric charging']],
            ],
            'PE' => [
                ['code' => 'gasohol_regular', 'name' => 'Gasohol regular', 'energy_type' => 'regular_gasoline', 'energy_unit' => 'litres', 'aliases' => ['gasohol 84', 'gasohol 90', 'regular']],
                ['code' => 'gasohol_premium', 'name' => 'Gasohol premium', 'energy_type' => 'premium_gasoline', 'energy_unit' => 'litres', 'aliases' => ['gasohol 95', 'gasohol 97', 'gasohol 98', 'premium']],
                ['code' => 'diesel', 'name' => 'Diesel B5', 'energy_type' => 'diesel', 'energy_unit' => 'litres', 'aliases' => ['diesel b5', 'diesel']],
                ['code' => 'glp', 'name' => 'GLP', 'energy_type' => 'lpg', 'energy_unit' => 'litres', 'aliases' => ['glp', 'gas licuado']],
                ['code' => 'electric', 'name' => 'Electric charging', 'energy_type' => 'electric', 'energy_unit' => 'kwh', 'aliases' => ['kwh', 'kilowatt hour', 'carga eléctrica', 'electric charging']],
            ],
            'CL' => [
                ['code' => 'gasolina_93', 'name' => 'Gasolina 93', 'energy_type' => 'regular_gasoline', 'energy_unit' => 'litres', 'aliases' => ['gasolina 93', '93 octanos']],
                ['code' => 'gasolina_premium', 'name' => 'Gasolina 95 / 97', 'energy_type' => 'premium_gasoline', 'energy_unit' => 'litres', 'aliases' => ['gasolina 95', 'gasolina 97', '95 octanos', '97 octanos']],
                ['code' => 'diesel', 'name' => 'Diesel', 'energy_type' => 'diesel', 'energy_unit' => 'litres', 'aliases' => ['diesel', 'petróleo diesel']],
                ['code' => 'glp', 'name' => 'GLP', 'energy_type' => 'lpg', 'energy_unit' => 'litres', 'aliases' => ['glp', 'gas licuado']],
                ['code' => 'electric', 'name' => 'Electric charging', 'energy_type' => 'electric', 'energy_unit' => 'kwh', 'aliases' => ['kwh', 'kilowatt hour', 'carga eléctrica', 'electric charging']],
            ],
        ];

        foreach ($products as $countryCode => $countryProducts) {
            $country = Country::query()->where('code', $countryCode)->first();

            if ($country === null) {
                continue;
            }

            foreach ($countryProducts as $product) {
                EnergyProduct::query()->updateOrCreate(
                    ['country_id' => $country->id, 'code' => $product['code']],
                    [...$product, 'country_id' => $country->id, 'is_active' => true],
                );
            }
        }
    }
}

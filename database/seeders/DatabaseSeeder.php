<?php

namespace Database\Seeders;

use App\Models\Country;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            CountrySeeder::class,
            EnergyProductSeeder::class,
        ]);

        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $country = Country::query()->where('code', 'EC')->firstOrFail();
        User::query()->updateOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'country_id' => $country->id,
                'password' => Hash::make('password'),
            ],
        );

        $this->call(EnergyInvoiceCsvSeeder::class);
    }
}

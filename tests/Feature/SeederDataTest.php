<?php

use App\Models\Country;
use App\Models\EnergyInvoice;
use App\Models\User;
use Database\Seeders\CountrySeeder;
use Database\Seeders\EnergyInvoiceCsvSeeder;
use Database\Seeders\EnergyProductSeeder;
use GigEconomics\Domain\Shared\EnergyType;
use GigEconomics\Domain\Shared\EnergyUnit;
use Illuminate\Support\Facades\Log;

test('invoice CSV seeder imports anonymized Ecuador supplier data idempotently', function () {
    (new CountrySeeder)->run();
    (new EnergyProductSeeder)->run();

    $country = Country::query()->where('code', 'EC')->firstOrFail();
    $user = User::factory()->create([
        'email' => 'test@example.com',
        'country_id' => $country->id,
    ]);
    Log::spy();

    (new EnergyInvoiceCsvSeeder)->run();
    (new EnergyInvoiceCsvSeeder)->run();

    $invoices = EnergyInvoice::query()->whereBelongsTo($user)->get();
    $invoice = $invoices->firstOrFail();

    expect($invoices)->toHaveCount(5)
        ->and($invoices->every(
            fn (EnergyInvoice $invoice): bool => str_starts_with($invoice->vendor_name, 'ESTACION DE PRUEBA')
                && str_starts_with($invoice->vendor_tax_id, '999'),
        ))->toBeTrue()
        ->and($invoice->vendor_name)->not->toBeEmpty()
        ->and($invoice->vendor_tax_id)->not->toBeEmpty()
        ->and($invoice->energy_type)->toBe(EnergyType::PremiumGasoline)
        ->and($invoice->energy_unit)->toBe(EnergyUnit::Gallons)
        ->and($invoice->country->code)->toBe('EC')
        ->and($invoice->energyProduct->code)->toBe('super')
        ->and($invoice->energyProduct->energy_unit)->toBe(EnergyUnit::Gallons);

    Log::shouldHaveReceived('warning')
        ->twice()
        ->with(
            'Skipping energy invoice CSV row with an invalid date.',
            Mockery::on(
                fn (array $context): bool => isset($context['csv_row'])
                    && ($context['date'] ?? null) === 'invalid-date',
            ),
        );
});

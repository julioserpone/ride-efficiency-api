<?php

use App\Models\FuelInvoice;
use App\Models\User;
use App\Support\FuelInvoiceEnergyLogFactory;
use GigEconomics\Domain\Analytics\EnergyAnalytics;
use GigEconomics\Domain\Energy\EnergyLog;
use GigEconomics\Domain\Shared\EnergyType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('translates a combustion invoice into a priced energy purchase', function () {
    $invoice = FuelInvoice::factory()->for(User::factory())->create([
        'fuel_volume_units' => 10.00,
        'unit_price' => 3.50,
        'tax_rate' => 15.00,
        'energy_type' => EnergyType::RegularGasoline,
        'status' => 'completed',
    ]);

    $purchase = (new FuelInvoiceEnergyLogFactory)->fromInvoice($invoice);

    expect($purchase)->not->toBeNull()
        ->and($purchase->isElectric())->toBeFalse()
        ->and($purchase->subtotal()->toDecimal()->__toString())->toBe('35.000000000')
        ->and($purchase->tax()->toDecimal()->__toString())->toBe('5.250000000000')
        ->and($purchase->total()->toDecimal()->__toString())->toBe('40.250000000000')
        ->and($purchase->reference)->toBe('fuel-invoice-'.$invoice->id);
});

test('translates an electric invoice into a purchase priced per kilowatt hour', function () {
    $invoice = FuelInvoice::factory()->for(User::factory())->electric()->create([
        'fuel_volume_units' => 45.00,
        'unit_price' => 0.40,
        'tax_rate' => 0.00,
        'status' => 'completed',
    ]);

    $purchase = (new FuelInvoiceEnergyLogFactory)->fromInvoice($invoice);

    expect($purchase)->not->toBeNull()
        ->and($purchase->isElectric())->toBeTrue()
        ->and($purchase->energy->kilowattHours(2)->__toString())->toBe('45.00')
        ->and($purchase->subtotal()->toDecimal()->__toString())->toBe('18.000000000');
});

test('skips invoices that are not completed or cannot be priced', function () {
    $factory = new FuelInvoiceEnergyLogFactory;

    $pending = FuelInvoice::factory()->for(User::factory())->create(['status' => 'pending']);
    $missingPrice = FuelInvoice::factory()->for(User::factory())->create([
        'unit_price' => null,
        'status' => 'completed',
    ]);

    expect($factory->fromInvoice($pending))->toBeNull()
        ->and($factory->fromInvoice($missingPrice))->toBeNull();
});

test('builds an energy log from a collection of invoices', function () {
    $user = User::factory()->create();

    FuelInvoice::factory()->for($user)->count(2)->create([
        'unit_price' => 3.50,
        'fuel_volume_units' => 10.00,
        'tax_rate' => 0.00,
        'status' => 'completed',
    ]);

    FuelInvoice::factory()->for($user)->create(['status' => 'pending']);

    $log = (new FuelInvoiceEnergyLogFactory)->fromInvoices($user->fuelInvoices);

    expect($log)->toBeInstanceOf(EnergyLog::class)
        ->and($log->count())->toBe(2)
        ->and($log->totalSpend()->toDecimal()->__toString())->toBe('70.000000000000');
});

test('derives energy economy from consecutive odometer readings', function () {
    $user = User::factory()->create();

    FuelInvoice::factory()->for($user)->create([
        'unit_price' => 3.00,
        'fuel_volume_units' => 10.00,
        'tax_rate' => 0.00,
        'odometer' => 10000,
        'energy_type' => EnergyType::RegularGasoline,
        'status' => 'completed',
    ]);

    FuelInvoice::factory()->for($user)->create([
        'unit_price' => 3.00,
        'fuel_volume_units' => 10.00,
        'tax_rate' => 0.00,
        'odometer' => 10300,
        'energy_type' => EnergyType::RegularGasoline,
        'status' => 'completed',
    ]);

    $log = (new FuelInvoiceEnergyLogFactory)->fromInvoices($user->fuelInvoices);

    // 300 km travelled on the 10 units bought at the later reading => 30 km/unit.
    expect($log->totalDistance()->toDecimal()->__toString())->toBe('300');

    $analytics = EnergyAnalytics::fromLog($log);

    expect($analytics->costPerKilometre(2)->__toString())->toBe('0.20');
});

test('reports the weighted energy price per unit', function () {
    $user = User::factory()->create();

    FuelInvoice::factory()->for($user)->create([
        'unit_price' => 2.00,
        'fuel_volume_units' => 10.00,
        'tax_rate' => 0.00,
        'status' => 'completed',
    ]);

    FuelInvoice::factory()->for($user)->create([
        'unit_price' => 4.00,
        'fuel_volume_units' => 30.00,
        'tax_rate' => 0.00,
        'status' => 'completed',
    ]);

    $analytics = EnergyAnalytics::fromLog(
        (new FuelInvoiceEnergyLogFactory)->fromInvoices($user->fuelInvoices)
    );

    // Spend = 10*2 + 30*4 = 140; energy = 40 => 3.50 per unit.
    expect($analytics->weightedPrice()->__toString())->toBe('3.500000');
});

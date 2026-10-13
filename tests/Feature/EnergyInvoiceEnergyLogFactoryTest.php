<?php

use App\Models\EnergyInvoice;
use App\Models\User;
use App\Support\EnergyInvoiceEnergyLogFactory;
use GigEconomics\Domain\Analytics\EnergyAnalytics;
use GigEconomics\Domain\Energy\EnergyLog;
use GigEconomics\Domain\Shared\EnergyType;
use GigEconomics\Domain\Shared\EnergyUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('translates a combustion invoice into a priced energy purchase', function () {
    $invoice = EnergyInvoice::factory()->for(User::factory())->create([
        'energy_amount' => 10.00,
        'fuel_volume_units' => 10.00,
        'unit_price' => 3.50,
        'tax_rate' => 15.00,
        'energy_type' => EnergyType::RegularGasoline,
        'status' => 'completed',
    ]);

    $purchase = (new EnergyInvoiceEnergyLogFactory)->fromInvoice($invoice);

    expect($purchase)->not->toBeNull()
        ->and($purchase->isElectric())->toBeFalse()
        ->and($purchase->subtotal()->toDecimal()->__toString())->toBe('35.000000000')
        ->and($purchase->tax()->toDecimal()->__toString())->toBe('5.250000000000')
        ->and($purchase->total()->toDecimal()->__toString())->toBe('40.250000000000')
        ->and($purchase->reference)->toBe('energy-invoice-'.$invoice->id);
});

test('translates an electric invoice into a purchase priced per kilowatt hour', function () {
    $invoice = EnergyInvoice::factory()->for(User::factory())->electric()->create([
        'energy_amount' => 45.00,
        'fuel_volume_units' => 45.00,
        'unit_price' => 0.40,
        'tax_rate' => 0.00,
        'status' => 'completed',
    ]);

    $purchase = (new EnergyInvoiceEnergyLogFactory)->fromInvoice($invoice);

    expect($purchase)->not->toBeNull()
        ->and($purchase->isElectric())->toBeTrue()
        ->and($purchase->energy->kilowattHours(2)->__toString())->toBe('45.00')
        ->and($purchase->subtotal()->toDecimal()->__toString())->toBe('18.000000000');
});

test('translates a litre invoice with a price per litre', function () {
    $invoice = EnergyInvoice::factory()->for(User::factory())->create([
        'energy_amount' => 20,
        'energy_unit' => EnergyUnit::Litres,
        'fuel_volume_units' => 20,
        'unit_price' => 1.25,
        'energy_type' => EnergyType::RegularGasoline,
        'status' => 'completed',
    ]);

    $purchase = (new EnergyInvoiceEnergyLogFactory)->fromInvoice($invoice);

    expect($purchase)->not->toBeNull()
        ->and($purchase->energy->unit)->toBe(EnergyUnit::Litres)
        ->and($purchase->unitPrice->unit)->toBe(EnergyUnit::Litres)
        ->and($purchase->subtotal()->toDecimal()->__toString())->toBe('25.000000000');
});

test('skips invoices that are not completed or cannot be priced', function () {
    $factory = new EnergyInvoiceEnergyLogFactory;

    $pending = EnergyInvoice::factory()->for(User::factory())->create(['status' => 'pending']);
    $missingPrice = EnergyInvoice::factory()->for(User::factory())->create([
        'unit_price' => null,
        'status' => 'completed',
    ]);
    $unclassified = EnergyInvoice::factory()->for(User::factory())->create([
        'energy_type' => null,
        'status' => 'completed',
    ]);

    expect($factory->fromInvoice($pending))->toBeNull()
        ->and($factory->fromInvoice($missingPrice))->toBeNull()
        ->and($factory->fromInvoice($unclassified))->toBeNull();
});

test('builds an energy log from a collection of invoices', function () {
    $user = User::factory()->create();

    EnergyInvoice::factory()->for($user)->count(2)->create([
        'unit_price' => 3.50,
        'energy_amount' => 10.00,
        'fuel_volume_units' => 10.00,
        'tax_rate' => 0.00,
        'status' => 'completed',
    ]);

    EnergyInvoice::factory()->for($user)->create(['status' => 'pending']);

    $log = (new EnergyInvoiceEnergyLogFactory)->fromInvoices($user->energyInvoices);

    expect($log)->toBeInstanceOf(EnergyLog::class)
        ->and($log->count())->toBe(2)
        ->and($log->totalSpend()->toDecimal()->__toString())->toBe('70.000000000000');
});

test('derives energy economy from consecutive odometer readings', function () {
    $user = User::factory()->create();

    EnergyInvoice::factory()->for($user)->create([
        'unit_price' => 3.00,
        'energy_amount' => 10.00,
        'fuel_volume_units' => 10.00,
        'tax_rate' => 0.00,
        'odometer' => 10000,
        'energy_type' => EnergyType::RegularGasoline,
        'status' => 'completed',
    ]);

    EnergyInvoice::factory()->for($user)->create([
        'unit_price' => 3.00,
        'energy_amount' => 10.00,
        'fuel_volume_units' => 10.00,
        'tax_rate' => 0.00,
        'odometer' => 10300,
        'energy_type' => EnergyType::RegularGasoline,
        'status' => 'completed',
    ]);

    $log = (new EnergyInvoiceEnergyLogFactory)->fromInvoices($user->energyInvoices);

    // 300 km travelled on the 10 units bought at the later reading => 30 km/unit.
    expect($log->totalDistance()->toDecimal()->__toString())->toBe('300');

    $analytics = EnergyAnalytics::fromLog($log);

    expect($analytics->costPerKilometre(2)->__toString())->toBe('0.20');
});

test('reports the weighted energy price per unit', function () {
    $user = User::factory()->create();

    EnergyInvoice::factory()->for($user)->create([
        'unit_price' => 2.00,
        'energy_amount' => 10.00,
        'fuel_volume_units' => 10.00,
        'tax_rate' => 0.00,
        'status' => 'completed',
    ]);

    EnergyInvoice::factory()->for($user)->create([
        'unit_price' => 4.00,
        'energy_amount' => 30.00,
        'fuel_volume_units' => 30.00,
        'tax_rate' => 0.00,
        'status' => 'completed',
    ]);

    $analytics = EnergyAnalytics::fromLog(
        (new EnergyInvoiceEnergyLogFactory)->fromInvoices($user->energyInvoices)
    );

    // Spend = 10*2 + 30*4 = 140; energy = 40 => 3.50 per unit.
    expect($analytics->weightedPrice()->__toString())->toBe('3.500000');
});

<?php

use GigEconomics\Domain\Shared\EnergyType;
use GigEconomics\Support\Configuration;

it('exposes the gig economics defaults in the published config file', function (): void {
    expect(config('gig-economics'))->toBe([
        'tax_rate_percent' => 15.0,
        'default_energy_type' => 'regular_gasoline',
        'target_profit_per_kilometre' => '0.15',
    ]);
});

it('builds a library configuration from the laravel config file', function (): void {
    $configuration = Configuration::fromArray(config('gig-economics'));

    expect($configuration->defaultTaxRatePercent)->toBe(15.0)
        ->and($configuration->defaultEnergyType)->toBe(EnergyType::RegularGasoline)
        ->and($configuration->defaultTargetProfitPerKilometre)->toBe('0.15');
});

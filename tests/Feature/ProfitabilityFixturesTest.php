<?php

use GigEconomics\Domain\Money\Currency;
use GigEconomics\Domain\Money\Money;
use GigEconomics\Domain\Money\Percentage;
use GigEconomics\Domain\Shared\Distance;
use GigEconomics\Domain\Shared\EnergyEconomy;
use GigEconomics\Domain\Shared\Platform;
use GigEconomics\Domain\Trip\Fare;
use GigEconomics\Domain\Trip\TripCostProfile;
use GigEconomics\Domain\Trip\TripProfitabilityCalculator;

uses()->group('parity');

test('php matches profitability contract fixtures', function () {
    $path = base_path('shared/profitability-fixtures/v1/cases.json');
    expect(file_exists($path))->toBeTrue();

    $contents = file_get_contents($path);
    expect($contents)->not->toBeFalse();
    $data = json_decode((string) $contents, true);
    expect($data)->not->toBeNull();
    expect($data['version'])->toBe('1');

    foreach ($data['cases'] as $case) {
        $in = $case['input'];
        $exp = $case['expected'];
        $currency = Currency::of($in['currency']);
        $platform = Platform::tryFrom($in['platform']) ?? Platform::Custom;
        $commission = Percentage::of($in['commission_rate']);
        $dep = Money::of($in['depreciation_per_km'], $currency);
        $fixed = Money::of($in['other_costs_per_trip'], $currency);

        if ($in['energy']['mode'] === 'combustion') {
            $econ = EnergyEconomy::ofKilometresPerGallon($in['energy']['economy']);
            $profile = TripCostProfile::forCombustion(
                pricePerGallon: Money::of($in['energy']['price_per_unit'], $currency),
                economy: $econ,
                depreciationPerKilometre: $dep,
                commissionRate: $commission,
                fixedCostPerTrip: $fixed,
            );
        } else {
            $econ = EnergyEconomy::ofKilometresPerKilowattHour($in['energy']['economy']);
            $profile = TripCostProfile::forElectric(
                pricePerKilowattHour: Money::of($in['energy']['price_per_unit'], $currency),
                economy: $econ,
                depreciationPerKilometre: $dep,
                commissionRate: $commission,
                fixedCostPerTrip: $fixed,
            );
        }

        $fare = Fare::create(
            Money::of($in['gross_fare'], $currency),
            Distance::ofKilometres($in['distance_km']),
            $in['duration_minutes'],
        );
        $deadhead = Distance::ofKilometres($in['deadhead_distance_km']);
        $target = Money::of($in['target_profit_per_km'], $currency);
        $result = TripProfitabilityCalculator::withDefaultTarget($target)
            ->calculate($fare, $profile, $platform, $deadhead);
        $bd = $result->breakdown;

        expect((string) $bd->netProfit->toDecimal())->toBe($exp['net_profit'])
            ->and((string) $result->breakEvenFare->toDecimal())->toBe($exp['break_even_fare'])
            ->and($result->verdict->value)->toBe($exp['verdict'])
            ->and((string) $bd->platformCommission->toDecimal())->toBe($exp['platform_commission'])
            ->and((string) $bd->energyCost->toDecimal())->toBe($exp['energy_cost'])
            ->and((string) $bd->depreciationCost->toDecimal())->toBe($exp['depreciation_cost'])
            ->and((string) $bd->otherCosts->toDecimal())->toBe($exp['other_costs'])
            ->and((string) $bd->grossFare->toDecimal())->toBe($exp['gross_fare']);
    }
});

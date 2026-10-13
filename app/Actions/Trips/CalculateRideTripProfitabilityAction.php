<?php

namespace App\Actions\Trips;

use App\Models\RideTrip;
use App\Models\Vehicle;
use GigEconomics\Domain\Money\Currency;
use GigEconomics\Domain\Money\Money;
use GigEconomics\Domain\Money\Percentage;
use GigEconomics\Domain\Shared\Distance;
use GigEconomics\Domain\Shared\EnergyEconomy;
use GigEconomics\Domain\Shared\Platform;
use GigEconomics\Domain\Trip\Fare;
use GigEconomics\Domain\Trip\TripCostProfile;
use GigEconomics\Domain\Trip\TripProfitabilityCalculator;
use GigEconomics\Support\Configuration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CalculateRideTripProfitabilityAction
{
    /**
     * Calculate and persist the profitability of one completed ride.
     *
     * @param  array{
     *     platform_name: string,
     *     gross_fare: int|float|string,
     *     distance_km: int|float|string,
     *     deadhead_distance_km?: int|float|string|null,
     *     duration_minutes?: int|null,
     *     commission_rate?: int|float|string|null,
     *     other_costs_per_trip?: int|float|string|null,
     *     daily_shift_id?: int|null
     * }  $attributes
     */
    public function execute(Vehicle $vehicle, array $attributes, string $energyPricePerUnit): RideTrip
    {
        $vehicle->loadMissing('country');
        $country = $vehicle->country;
        $currencyCode = $country->currency_code;
        $currency = Currency::of($currencyCode);
        $platform = Platform::tryFrom(Str::lower($attributes['platform_name'])) ?? Platform::Custom;
        $commissionRate = $attributes['commission_rate']
            ?? $vehicle->commission_rate
            ?? $platform->defaultCommissionPercentage();
        $distance = Distance::ofKilometres((string) $attributes['distance_km']);
        $deadheadDistance = Distance::ofKilometres((string) ($attributes['deadhead_distance_km'] ?? '0'));
        $depreciationRate = Money::of((string) $country->depreciation_rate_per_km, $currency);
        $fixedCosts = Money::of((string) ($attributes['other_costs_per_trip'] ?? '0'), $currency);
        $profile = TripCostProfile::create(
            energyPricePerUnit: Money::of($energyPricePerUnit, $currency),
            energyEconomy: EnergyEconomy::of(
                (string) $vehicle->energy_economy,
                $vehicle->energy_unit,
            ),
            depreciationPerKilometre: $depreciationRate,
            commissionRate: Percentage::of((string) $commissionRate),
            fixedCostPerTrip: $fixedCosts,
        );
        $configuration = Configuration::fromArray(config('gig-economics', []));
        $profitability = TripProfitabilityCalculator::withDefaultTarget(
            Money::of($configuration->defaultTargetProfitPerKilometre, $currency),
        )->calculate(
            Fare::create(
                Money::of((string) $attributes['gross_fare'], $currency),
                $distance,
                isset($attributes['duration_minutes']) ? (int) $attributes['duration_minutes'] : null,
            ),
            $profile,
            $platform,
            $deadheadDistance,
        );
        $breakdown = $profitability->breakdown;

        return DB::transaction(fn (): RideTrip => $vehicle->user->rideTrips()->create([
            'vehicle_id' => $vehicle->id,
            'daily_shift_id' => isset($attributes['daily_shift_id']) ? (int) $attributes['daily_shift_id'] : null,
            'platform_name' => $attributes['platform_name'],
            'currency_code' => $currencyCode,
            'gross_fare' => $breakdown->grossFare->toDecimal(),
            'distance_km' => $distance->toDecimal(),
            'deadhead_distance_km' => $deadheadDistance->toDecimal(),
            'duration_minutes' => isset($attributes['duration_minutes']) ? (int) $attributes['duration_minutes'] : null,
            'commission_rate' => $commissionRate,
            'energy_price_per_unit' => $energyPricePerUnit,
            'energy_cost' => $breakdown->energyCost->toDecimal(),
            'commission_cost' => $breakdown->platformCommission->toDecimal(),
            'depreciation_cost' => $breakdown->depreciationCost->toDecimal(),
            'other_costs' => $breakdown->otherCosts->toDecimal(),
            'net_profit' => $breakdown->netProfit->toDecimal(),
            'break_even_fare' => $profitability->breakEvenFare->toDecimal(),
            'verdict' => $profitability->verdict->value,
        ]));
    }
}

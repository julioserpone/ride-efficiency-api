<?php

namespace App\Actions\Offers;

use App\Models\RideOffer;
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
use Illuminate\Support\Carbon;

class RecomputeRideOfferProfitabilityAction
{
    /**
     * Recompute server-side verdict for an offer. Returns null when the inputs
     * needed (vehicle + energy price) are not available.
     *
     * @return array{server_verdict:string,server_net_profit_estimate:string,server_break_even_fare:string,calculation_version:string}|null
     */
    public function execute(RideOffer $offer, ?Vehicle $vehicle = null): ?array
    {
        if ($vehicle === null && $offer->vehicle_id !== null) {
            $vehicle = Vehicle::query()->with('country')->find($offer->vehicle_id);
        }

        if ($vehicle === null) {
            return null;
        }

        $vehicle->loadMissing('country');
        $country = $vehicle->country;

        if ($country === null) {
            return null;
        }

        $user = $offer->user;
        $currencyCode = $country->currency_code;
        $currency = Currency::of($currencyCode);

        $energyPrice = $user->energyInvoices()
            ->where('country_id', $country->id)
            ->where('energy_type', $vehicle->energy_type->value)
            ->where('energy_unit', $vehicle->energy_unit->value)
            ->where('currency_code', $currencyCode)
            ->where('status', 'completed')
            ->whereNotNull('unit_price')
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->value('unit_price');

        if ($energyPrice === null) {
            return null;
        }

        $platform = Platform::tryFrom(\Illuminate\Support\Str::lower($offer->platform)) ?? Platform::Custom;
        $commissionRate = $vehicle->commission_rate ?? $platform->defaultCommissionPercentage();

        $grossFare = (string) $offer->offered_fare;
        $surge = (string) ($offer->surge_amount ?? '0');
        $tip = (string) ($offer->tip_amount ?? '0');
        $toll = (string) ($offer->toll_amount ?? '0');

        $effectiveGross = bcadd(bcadd($grossFare, $surge, 6), $tip, 6);
        $otherCosts = $toll;

        $distance = (string) $offer->estimated_distance_km;
        $deadhead = (string) ($offer->pickup_distance_km ?? '0');

        $profile = TripCostProfile::create(
            energyPricePerUnit: Money::of((string) $energyPrice, $currency),
            energyEconomy: \GigEconomics\Domain\Shared\EnergyEconomy::of(
                (string) $vehicle->energy_economy,
                $vehicle->energy_unit,
            ),
            depreciationPerKilometre: Money::of((string) $country->depreciation_rate_per_km, $currency),
            commissionRate: Percentage::of((string) $commissionRate),
            fixedCostPerTrip: Money::of($otherCosts, $currency),
        );

        $configuration = Configuration::fromArray(config('gig-economics', []));
        $configSnapshot = $offer->config_snapshot;
        $targetPerKm = is_array($configSnapshot) && isset($configSnapshot['target_profit_per_km'])
            ? $configSnapshot['target_profit_per_km']
            : $configuration->defaultTargetProfitPerKilometre;

        $profitability = TripProfitabilityCalculator::withDefaultTarget(
            Money::of((string) $targetPerKm, $currency),
        )->calculate(
            Fare::create(
                Money::of($effectiveGross, $currency),
                Distance::ofKilometres($distance),
                $offer->estimated_duration_minutes,
            ),
            $profile,
            $platform,
            Distance::ofKilometres($deadhead),
        );

        return [
            'server_verdict' => $profitability->verdict->value,
            'server_net_profit_estimate' => (string) $profitability->breakdown->netProfit->toDecimal(),
            'server_break_even_fare' => (string) $profitability->breakEvenFare->toDecimal(),
            'calculation_version' => '1',
        ];
    }
}
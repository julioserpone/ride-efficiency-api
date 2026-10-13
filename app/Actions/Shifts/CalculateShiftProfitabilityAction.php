<?php

namespace App\Actions\Shifts;

use App\Models\Country;
use App\Models\DailyShift;
use GigEconomics\Domain\Money\Currency;
use GigEconomics\Domain\Money\Money;
use GigEconomics\Domain\Shared\Distance;
use GigEconomics\Domain\Trip\TripCostProfile;
use GigEconomics\Domain\Trip\TripProfitabilityCalculator;
use GigEconomics\Support\Configuration;
use Illuminate\Support\Facades\DB;

class CalculateShiftProfitabilityAction
{
    /**
     * Execute the shift profitability calculation.
     */
    public function execute(
        DailyShift $shift,
        ?float $appliedFuelCost = null,
        ?float $customDepreciation = null
    ): DailyShift {
        return DB::transaction(function () use ($shift, $appliedFuelCost, $customDepreciation) {
            $currency = $this->resolveCurrency($shift);

            $fuelCost = Money::fromDecimal(
                $appliedFuelCost !== null ? (string) $appliedFuelCost : $shift->applied_fuel_cost,
                $currency,
            );

            $depreciation = $customDepreciation !== null
                ? Money::fromDecimal((string) $customDepreciation, $currency)
                : $this->depreciationFor($shift, $currency);

            $result = TripProfitabilityCalculator::forCostProfile(
                $this->costProfile($currency)
            )->calculate(
                grossEarnings: $this->sumEarnings($shift, $currency),
                fuelCost: $fuelCost,
                depreciation: $depreciation,
                distance: Distance::fromKilometres($shift->total_km_gps),
            );

            $shift->update([
                'applied_fuel_cost' => $result->fuelCost->toDecimal(),
                'estimated_depreciation' => $result->depreciation->toDecimal(),
                'real_net_profit' => $result->netProfit->toDecimal(),
            ]);

            return $shift->fresh(['earnings']);
        });
    }

    /**
     * Build the trip cost profile from the published gig-economics configuration.
     */
    protected function costProfile(Currency $currency): TripCostProfile
    {
        $configuration = Configuration::fromArray(config('gig-economics', []));

        return TripCostProfile::standard(
            currency: $currency,
            taxRatePercent: $configuration->defaultTaxRatePercent,
            energyType: $configuration->defaultEnergyType,
            targetProfitPerKilometre: Money::fromDecimal(
                $configuration->defaultTargetProfitPerKilometre,
                $currency,
            ),
        );
    }

    /**
     * Sum every earning registered against the shift.
     */
    protected function sumEarnings(DailyShift $shift, Currency $currency): Money
    {
        $total = Money::zero($currency);

        foreach ($shift->earnings as $earning) {
            $total = $total->plus(Money::fromDecimal($earning->gross_amount, $currency));
        }

        return $total;
    }

    /**
     * Calculate the vehicle depreciation for the shift's distance.
     */
    protected function depreciationFor(DailyShift $shift, Currency $currency): Money
    {
        return Distance::fromKilometres($shift->total_km_gps)->multipliedBy(
            Money::fromDecimal((string) $this->resolveDepreciationRatePerKm($shift), $currency)
        );
    }

    /**
     * Resolve the currency the shift's amounts are expressed in.
     */
    protected function resolveCurrency(DailyShift $shift): Currency
    {
        $country = $this->resolveCountry($shift);

        if ($country) {
            return Currency::of($country->currency_code);
        }

        return Currency::of((string) config('app.currency', 'USD'));
    }

    /**
     * Resolve the appropriate depreciation rate per kilometer for the shift's user/country.
     */
    protected function resolveDepreciationRatePerKm(DailyShift $shift): float
    {
        $country = $this->resolveCountry($shift);

        if ($country) {
            return (float) $country->depreciation_rate_per_km;
        }

        return (float) config('efficiency.depreciation_rate_per_km', 0.15);
    }

    /**
     * Resolve the country of the shift's user, loading the relation on demand.
     */
    protected function resolveCountry(DailyShift $shift): ?Country
    {
        $user = $shift->user;

        if ($user && $user->relationLoaded('country') === false) {
            $user->load('country');
        }

        return $user?->country;
    }
}

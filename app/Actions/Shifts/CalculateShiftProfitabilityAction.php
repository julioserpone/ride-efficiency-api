<?php

namespace App\Actions\Shifts;

use App\Models\Country;
use App\Models\DailyShift;
use GigEconomics\Domain\Money\Currency;
use GigEconomics\Domain\Money\Money;
use GigEconomics\Domain\Money\Percentage;
use GigEconomics\Domain\Shared\Distance;
use GigEconomics\Domain\Shared\EnergyEconomy;
use GigEconomics\Domain\Trip\Fare;
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

            $energyCost = Money::of(
                $appliedFuelCost !== null ? (string) $appliedFuelCost : ($shift->applied_fuel_cost ?? '0'),
                $currency,
            );

            $depreciation = $customDepreciation !== null
                ? Money::of((string) $customDepreciation, $currency)
                : $this->depreciationFor($shift, $currency);

            $distance = Distance::ofKilometres($shift->total_km_gps);

            $breakdown = TripProfitabilityCalculator::withDefaultTarget(
                $this->targetProfitPerKilometre($currency)
            )->calculate(
                Fare::create($this->sumEarnings($shift, $currency), $distance),
                $this->costProfile($energyCost, $depreciation, $distance),
            )->breakdown;

            $shift->update([
                'applied_fuel_cost' => $breakdown->energyCost->toDecimal(),
                'estimated_depreciation' => $breakdown->depreciationCost->toDecimal(),
                'real_net_profit' => $breakdown->netProfit->toDecimal(),
            ]);

            return $shift->fresh(['earnings']);
        });
    }

    /**
     * Build the trip cost profile from the shift's own energy and depreciation
     * figures, so the library prices the shift exactly as the driver reported it.
     *
     * The profile is expressed as a per-kilometre depreciation rate instead of a
     * total, because the calculator multiplies it by the trip distance. The
     * energy price per unit is normalised so the economy reading of one kilometre
     * per unit cancels out and the profile yields the shift's actual energy cost.
     */
    protected function costProfile(Money $energyCost, Money $depreciation, Distance $distance): TripCostProfile
    {
        $currency = $energyCost->currency;

        $depreciationPerKilometre = $distance->isPositive()
            ? Money::of($depreciation->toDecimal()->dividedBy($distance->toDecimal(), 12)->__toString(), $currency)
            : Money::zero($currency);

        return TripCostProfile::create(
            energyPricePerUnit: Money::of($energyCost->toDecimal()->__toString(), $currency),
            energyEconomy: EnergyEconomy::ofKilometresPerGallon($distance->toDecimal()->__toString()),
            depreciationPerKilometre: $depreciationPerKilometre,
            commissionRate: Percentage::zero(),
        );
    }

    /**
     * Minimum acceptable profit per kilometre, from the published configuration.
     */
    protected function targetProfitPerKilometre(Currency $currency): Money
    {
        $configuration = Configuration::fromArray(config('gig-economics', []));

        return Money::of($configuration->defaultTargetProfitPerKilometre, $currency);
    }

    /**
     * Sum every earning registered against the shift.
     */
    protected function sumEarnings(DailyShift $shift, Currency $currency): Money
    {
        $total = Money::zero($currency);

        foreach ($shift->earnings as $earning) {
            $total = $total->plus(Money::of((string) $earning->gross_amount, $currency));
        }

        return $total;
    }

    /**
     * Calculate the vehicle depreciation for the shift's distance.
     */
    protected function depreciationFor(DailyShift $shift, Currency $currency): Money
    {
        return Money::of(
            Distance::ofKilometres($shift->total_km_gps)
                ->toDecimal()
                ->multipliedBy((string) $this->resolveDepreciationRatePerKm($shift))
                ->__toString(),
            $currency,
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

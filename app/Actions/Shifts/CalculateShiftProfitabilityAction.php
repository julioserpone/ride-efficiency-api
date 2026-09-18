<?php

namespace App\Actions\Shifts;

use App\Models\DailyShift;
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
            $totalEarnings = (float) $shift->earnings()->sum('gross_amount');

            $fuelCost = $appliedFuelCost !== null
                ? round($appliedFuelCost, 2)
                : (float) $shift->applied_fuel_cost;

            $ratePerKm = $this->resolveDepreciationRatePerKm($shift);

            $depreciation = $customDepreciation !== null
                ? round($customDepreciation, 2)
                : round((float) $shift->total_km_gps * $ratePerKm, 2);

            $netProfit = round($totalEarnings - $fuelCost - $depreciation, 2);

            $shift->update([
                'applied_fuel_cost' => $fuelCost,
                'estimated_depreciation' => $depreciation,
                'real_net_profit' => $netProfit,
            ]);

            return $shift->fresh(['earnings']);
        });
    }

    /**
     * Resolve the appropriate depreciation rate per kilometer for the shift's user/country.
     */
    protected function resolveDepreciationRatePerKm(DailyShift $shift): float
    {
        $user = $shift->user;

        if ($user && $user->relationLoaded('country') === false) {
            $user->load('country');
        }

        if ($user && $user->country) {
            return (float) $user->country->depreciation_rate_per_km;
        }

        return (float) config('efficiency.depreciation_rate_per_km', 0.15);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use GigEconomics\Support\Configuration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfitabilityProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $request->validate([
            'vehicle_id' => ['nullable', 'integer'],
        ]);

        $user = $request->user();
        $vehicle = null;

        if ($request->has('vehicle_id')) {
            $vehicle = Vehicle::query()
                ->with('country')
                ->where('user_id', $user->id)
                ->findOrFail($request->integer('vehicle_id'));
        }

        $country = $vehicle === null ? $user->country()->first() : $vehicle->country;

        $energyPricePerUnit = null;
        if ($vehicle !== null && $country !== null) {
            $energyPricePerUnit = $user->energyInvoices()
                ->where('country_id', $country->id)
                ->where('energy_type', $vehicle->energy_type->value)
                ->where('energy_unit', $vehicle->energy_unit->value)
                ->where('currency_code', $country->currency_code)
                ->where('status', 'completed')
                ->whereNotNull('unit_price')
                ->orderByDesc('invoice_date')
                ->orderByDesc('id')
                ->value('unit_price');
        }

        $configuration = Configuration::fromArray(config('gig-economics', []));

        $commissionRate = $vehicle?->commission_rate;

        return response()->json([
            'profile' => [
                'calculation_version' => '1',
                'updated_at' => now()->toIso8601String(),
                'currency_code' => $country?->currency_code,
                'currency_symbol' => $country?->currency_symbol,
                'country' => $country === null ? null : [
                    'code' => $country->code,
                    'name' => $country->name,
                    'depreciation_rate_per_km' => (string) $country->depreciation_rate_per_km,
                ],
                'vehicle' => $vehicle === null ? null : [
                    'id' => $vehicle->id,
                    'name' => $vehicle->name,
                    'energy_type' => $vehicle->energy_type->value,
                    'energy_unit' => $vehicle->energy_unit->value,
                    'energy_economy' => (string) $vehicle->energy_economy,
                    'commission_rate' => $commissionRate === null ? null : (string) $commissionRate,
                ],
                'energy_price_per_unit' => $energyPricePerUnit === null ? null : (string) $energyPricePerUnit,
                'target_profit_per_km' => $configuration->defaultTargetProfitPerKilometre,
                'config' => [
                    'tax_rate_percent' => $configuration->defaultTaxRatePercent,
                    'default_energy_type' => $configuration->defaultEnergyType->value,
                ],
            ],
        ]);
    }
}

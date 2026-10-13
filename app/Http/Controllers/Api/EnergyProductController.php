<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Country;
use Illuminate\Http\JsonResponse;

class EnergyProductController extends Controller
{
    public function index(string $countryCode): JsonResponse
    {
        $country = Country::query()
            ->where('code', strtoupper($countryCode))
            ->where('is_active', true)
            ->firstOrFail();

        $products = $country->energyProducts()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'energy_type', 'energy_unit']);

        return response()->json([
            'country' => ['code' => $country->code, 'name' => $country->name],
            'products' => $products,
        ]);
    }
}

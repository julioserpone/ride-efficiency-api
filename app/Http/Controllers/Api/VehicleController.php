<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreVehicleRequest;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehicleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $vehicles = $request->user()
            ->vehicles()
            ->with('country')
            ->where('is_active', true)
            ->orderBy('id')
            ->paginate(15);

        return response()->json(['vehicles' => $vehicles]);
    }

    public function store(StoreVehicleRequest $request): JsonResponse
    {
        /** @var Vehicle $vehicle */
        $vehicle = $request->user()->vehicles()->create($request->validated());

        return response()->json([
            'message' => 'Vehicle profile created successfully.',
            'vehicle' => $vehicle->load('country'),
        ], 201);
    }
}

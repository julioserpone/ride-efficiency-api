<?php

namespace App\Http\Controllers\Api;

use App\Actions\Trips\CalculateRideTripProfitabilityAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRideTripRequest;
use App\Models\RideOffer;
use App\Models\RideTrip;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RideTripController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $trips = $request->user()
            ->rideTrips()
            ->with('vehicle')
            ->latest()
            ->paginate(15);

        return response()->json(['trips' => $trips]);
    }

    public function store(
        StoreRideTripRequest $request,
        Vehicle $vehicle,
        CalculateRideTripProfitabilityAction $calculator,
    ): JsonResponse {
        $attributes = [
            'platform_name' => $request->string('platform_name')->toString(),
            'gross_fare' => (string) $request->input('gross_fare'),
            'distance_km' => (string) $request->input('distance_km'),
            'deadhead_distance_km' => $request->input('deadhead_distance_km') === null
                ? null
                : (string) $request->input('deadhead_distance_km'),
            'duration_minutes' => $request->has('duration_minutes')
                ? $request->integer('duration_minutes')
                : null,
            'commission_rate' => $request->input('commission_rate') === null
                ? null
                : (string) $request->input('commission_rate'),
            'other_costs_per_trip' => $request->input('other_costs_per_trip') === null
                ? null
                : (string) $request->input('other_costs_per_trip'),
            'daily_shift_id' => $request->has('daily_shift_id')
                ? $request->integer('daily_shift_id')
                : null,
        ];
        $country = $vehicle->country()->firstOrFail();

        $energyPricePerUnit = $request->filled('energy_price_per_unit')
            ? $request->string('energy_price_per_unit')->toString()
            : $request->user()->energyInvoices()
                ->where('country_id', $country->id)
                ->where('energy_type', $vehicle->energy_type->value)
                ->where('energy_unit', $vehicle->energy_unit->value)
                ->where('currency_code', $country->currency_code)
                ->where('status', 'completed')
                ->whereNotNull('unit_price')
                ->orderByDesc('invoice_date')
                ->orderByDesc('id')
                ->value('unit_price');

        if ($energyPricePerUnit === null) {
            throw ValidationException::withMessages([
                'energy_price_per_unit' => 'Provide a unit price or add a completed matching energy invoice first.',
            ]);
        }

        $trip = $calculator->execute($vehicle, $attributes, (string) $energyPricePerUnit);

        if ($request->filled('ride_offer_id')) {
            $offer = RideOffer::query()
                ->where('user_id', $request->user()->id)
                ->find((int) $request->input('ride_offer_id'));

            if ($offer !== null) {
                $offer->update([
                    'ride_trip_id' => $trip->id,
                    'actual_distance_km' => $trip->distance_km,
                    'actual_fare' => $trip->gross_fare,
                    'actual_duration_minutes' => $trip->duration_minutes,
                    'status' => 'completed',
                    'completed_at' => now(),
                ]);

                $alreadyCompleted = $offer->events()->where('event_type', 'completed')->exists();

                if (! $alreadyCompleted) {
                    $offer->events()->create([
                        'event_type' => 'completed',
                        'occurred_at' => now(),
                        'metadata' => ['ride_trip_id' => $trip->id],
                    ]);
                }
            }
        }

        return response()->json([
            'message' => 'Ride profitability calculated successfully.',
            'trip' => $trip,
        ], 201);
    }

    public function show(Request $request, RideTrip $rideTrip): JsonResponse
    {
        if ($rideTrip->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        return response()->json(['trip' => $rideTrip->load('vehicle')]);
    }
}

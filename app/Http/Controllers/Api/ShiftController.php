<?php

namespace App\Http\Controllers\Api;

use App\Actions\Shifts\CalculateShiftProfitabilityAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\CloseShiftRequest;
use App\Http\Requests\StoreShiftRequest;
use App\Models\DailyShift;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ShiftController extends Controller
{
    /**
     * Display a listing of daily shifts for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $shifts = $user->dailyShifts()
            ->with('earnings')
            ->orderBy('shift_date', 'desc')
            ->paginate(15);

        $summary = [
            'total_shifts' => $user->dailyShifts()->count(),
            'total_net_profit' => (float) $user->dailyShifts()->sum('real_net_profit'),
            'total_km_gps' => (float) $user->dailyShifts()->sum('total_km_gps'),
            'total_trips_completed' => (int) $user->dailyShifts()->sum('total_trips_completed'),
        ];

        return response()->json([
            'summary' => $summary,
            'shifts' => $shifts,
        ]);
    }

    /**
     * Store/start a new daily shift.
     */
    public function store(StoreShiftRequest $request, CalculateShiftProfitabilityAction $calculator): JsonResponse
    {
        $user = $request->user();
        $shiftDate = $request->input('shift_date', Carbon::today()->toDateString());

        $existingShift = $user->dailyShifts()->whereDate('shift_date', $shiftDate)->first();

        if ($existingShift) {
            return response()->json([
                'message' => 'A shift already exists for this date.',
                'shift' => $existingShift->load('earnings'),
            ], 422);
        }

        /** @var DailyShift $shift */
        $shift = DB::transaction(function () use ($user, $shiftDate, $request, $calculator): DailyShift {
            /** @var DailyShift $newShift */
            $newShift = $user->dailyShifts()->create([
                'shift_date' => $shiftDate,
                'total_km_gps' => $request->input('total_km_gps', 0.00),
                'total_minutes_connected' => $request->input('total_minutes_connected', 0),
                'total_trips_completed' => $request->input('total_trips_completed', 0),
                'total_offers_scanned' => $request->input('total_offers_scanned', 0),
            ]);

            if ($request->has('earnings')) {
                foreach ($request->input('earnings') as $earning) {
                    $newShift->earnings()->create([
                        'provider_name' => $earning['provider_name'],
                        'gross_amount' => $earning['gross_amount'],
                    ]);
                }
            }

            return $calculator->execute($newShift);
        });

        return response()->json([
            'message' => 'Shift created successfully.',
            'shift' => $shift,
        ], 201);
    }

    /**
     * Display the specified daily shift.
     */
    public function show(Request $request, DailyShift $shift): JsonResponse
    {
        if ($shift->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        return response()->json([
            'shift' => $shift->load('earnings'),
        ]);
    }

    /**
     * Finalize and close the daily shift, calculating real net profitability.
     */
    public function close(CloseShiftRequest $request, DailyShift $shift, CalculateShiftProfitabilityAction $calculator): JsonResponse
    {
        if ($shift->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $shift = DB::transaction(function () use ($shift, $request, $calculator) {
            $shift->update(array_filter([
                'total_km_gps' => $request->input('total_km_gps', $shift->total_km_gps),
                'total_minutes_connected' => $request->input('total_minutes_connected', $shift->total_minutes_connected),
                'total_trips_completed' => $request->input('total_trips_completed', $shift->total_trips_completed),
                'total_offers_scanned' => $request->input('total_offers_scanned', $shift->total_offers_scanned),
            ], fn ($val) => $val !== null));

            if ($request->has('earnings')) {
                foreach ($request->input('earnings') as $earning) {
                    $shift->earnings()->create([
                        'provider_name' => $earning['provider_name'],
                        'gross_amount' => $earning['gross_amount'],
                    ]);
                }
            }

            return $calculator->execute(
                $shift,
                $request->has('applied_fuel_cost') ? (float) $request->input('applied_fuel_cost') : null,
                $request->has('estimated_depreciation') ? (float) $request->input('estimated_depreciation') : null
            );
        });

        return response()->json([
            'message' => 'Shift closed and profitability calculated successfully.',
            'shift' => $shift,
        ]);
    }
}

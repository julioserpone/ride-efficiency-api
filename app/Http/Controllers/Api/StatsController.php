<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DailyShift;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class StatsController extends Controller
{
    /**
     * Weekly aggregated stats for the last 8 weeks.
     */
    public function weekly(Request $request): JsonResponse
    {
        $user = $request->user();

        $weeks = $user->dailyShifts()
            ->where('shift_date', '>=', Carbon::now()->subWeeks(8)->startOfWeek())
            ->get([
                'shift_date',
                'real_net_profit',
                'total_km_gps',
                'applied_fuel_cost',
                'estimated_depreciation',
                'total_trips_completed',
            ])
            ->groupBy(fn ($shift) => $shift->shift_date->startOfWeek()->toDateString())
            ->map(fn ($group, $weekStart) => [
                'week_start' => $weekStart,
                'total_shifts' => $group->count(),
                'total_net_profit' => $group->sum('real_net_profit'),
                'total_km' => $group->sum('total_km_gps'),
                'total_fuel_cost' => $group->sum('applied_fuel_cost'),
                'total_depreciation' => $group->sum('estimated_depreciation'),
                'total_trips' => $group->sum('total_trips_completed'),
                'avg_daily_profit' => round($group->avg('real_net_profit'), 2),
            ])
            ->sortBy(fn ($entry) => $entry['week_start'])
            ->values();

        return response()->json(['weekly' => $weeks]);
    }

    /**
     * Monthly aggregated stats for the last 6 months.
     */
    public function monthly(Request $request): JsonResponse
    {
        $user = $request->user();

        $months = $user->dailyShifts()
            ->where('shift_date', '>=', Carbon::now()->subMonths(6)->startOfMonth())
            ->get([
                'shift_date',
                'real_net_profit',
                'total_km_gps',
                'applied_fuel_cost',
                'estimated_depreciation',
                'total_trips_completed',
            ])
            ->groupBy(fn ($shift) => $shift->shift_date->startOfMonth()->toDateString())
            ->map(fn ($group, $monthStart) => [
                'month_start' => $monthStart,
                'total_shifts' => $group->count(),
                'total_net_profit' => $group->sum('real_net_profit'),
                'total_km' => $group->sum('total_km_gps'),
                'total_fuel_cost' => $group->sum('applied_fuel_cost'),
                'total_depreciation' => $group->sum('estimated_depreciation'),
                'total_trips' => $group->sum('total_trips_completed'),
                'avg_daily_profit' => round($group->avg('real_net_profit'), 2),
            ])
            ->sortBy(fn ($entry) => $entry['month_start'])
            ->values();

        return response()->json(['monthly' => $months]);
    }

    /**
     * Global summary totals, averages, best/worst shift.
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $shiftsQuery = $user->dailyShifts();

        $totals = (clone $shiftsQuery)->select(
            DB::raw('COUNT(*) AS total_shifts'),
            DB::raw('SUM(real_net_profit) AS total_net_profit'),
            DB::raw('SUM(total_km_gps) AS total_km'),
            DB::raw('SUM(applied_fuel_cost) AS total_fuel_cost'),
            DB::raw('SUM(estimated_depreciation) AS total_depreciation'),
            DB::raw('SUM(total_trips_completed) AS total_trips'),
            DB::raw('ROUND(AVG(real_net_profit), 2) AS avg_daily_profit'),
            DB::raw('ROUND(AVG(total_km_gps), 2) AS avg_daily_km'),
        )->first();

        $bestShift = (clone $shiftsQuery)
            ->orderBy('real_net_profit', 'desc')
            ->select('id', 'shift_date', 'real_net_profit', 'total_trips_completed', 'total_km_gps')
            ->first();

        $worstShift = (clone $shiftsQuery)
            ->orderBy('real_net_profit', 'asc')
            ->select('id', 'shift_date', 'real_net_profit', 'total_trips_completed', 'total_km_gps')
            ->first();

        $currentMonthProfit = (clone $shiftsQuery)
            ->whereMonth('shift_date', Carbon::now()->month)
            ->whereYear('shift_date', Carbon::now()->year)
            ->sum('real_net_profit');

        return response()->json([
            'totals' => $totals,
            'best_shift' => $bestShift,
            'worst_shift' => $worstShift,
            'current_month_profit' => (float) $currentMonthProfit,
        ]);
    }

    /**
     * Efficiency ratios: profit/km, profit/hour, fuel cost/km.
     */
    public function efficiency(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $user->dailyShifts()
            ->select(
                DB::raw('COUNT(*) AS total_shifts'),
                DB::raw('SUM(real_net_profit) AS total_net_profit'),
                DB::raw('SUM(total_km_gps) AS total_km'),
                DB::raw('SUM(total_minutes_connected) AS total_minutes'),
                DB::raw('SUM(applied_fuel_cost) AS total_fuel_cost'),
            )
            ->first();

        $totalKm = (float) ($data->total_km ?? 0);
        $totalHours = (float) ($data->total_minutes ?? 0) / 60;
        $totalProfit = (float) ($data->total_net_profit ?? 0);
        $totalFuelCost = (float) ($data->total_fuel_cost ?? 0);

        return response()->json([
            'efficiency' => [
                'profit_per_km' => $totalKm > 0 ? round($totalProfit / $totalKm, 2) : 0,
                'profit_per_hour' => $totalHours > 0 ? round($totalProfit / $totalHours, 2) : 0,
                'fuel_cost_per_km' => $totalKm > 0 ? round($totalFuelCost / $totalKm, 2) : 0,
                'avg_km_per_shift' => ($data->total_shifts ?? 0) > 0 ? round($totalKm / $data->total_shifts, 2) : 0,
                'avg_minutes_per_shift' => ($data->total_shifts ?? 0) > 0 ? round((float) $data->total_minutes / $data->total_shifts, 0) : 0,
            ],
        ]);
    }
}

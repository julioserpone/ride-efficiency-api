<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Render the main dashboard with pre-loaded stats and shifts.
     */
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        // Recent shifts (last 30) with earnings loaded
        $recentShifts = $user->dailyShifts()
            ->with('earnings')
            ->orderBy('shift_date', 'desc')
            ->limit(30)
            ->get();

        // Global summary
        $summary = $user->dailyShifts()->select(
            DB::raw('COUNT(*) AS total_shifts'),
            DB::raw('COALESCE(SUM(real_net_profit), 0) AS total_net_profit'),
            DB::raw('COALESCE(SUM(total_km_gps), 0) AS total_km'),
            DB::raw('COALESCE(SUM(applied_fuel_cost), 0) AS total_fuel_cost'),
            DB::raw('COALESCE(SUM(total_trips_completed), 0) AS total_trips'),
        )->first();

        // Current month profit
        $currentMonthProfit = (float) $user->dailyShifts()
            ->whereMonth('shift_date', Carbon::now()->month)
            ->whereYear('shift_date', Carbon::now()->year)
            ->sum('real_net_profit');

        $weeklyShifts = $user->dailyShifts()
            ->where('shift_date', '>=', Carbon::now()->subWeeks(8)->startOfWeek())
            ->get(['shift_date', 'real_net_profit', 'total_km_gps', 'applied_fuel_cost']);

        $weeklyData = $weeklyShifts
            ->groupBy(fn ($shift) => $shift->shift_date->startOfWeek()->format('d M'))
            ->map(fn ($group, $weekLabel) => [
                'week_start' => $weekLabel,
                'total_net_profit' => $group->sum('real_net_profit'),
                'total_km' => $group->sum('total_km_gps'),
                'total_fuel_cost' => $group->sum('applied_fuel_cost'),
            ])
            ->sortBy(fn ($entry) => Carbon::parse($entry['week_start'])->startOfWeek())
            ->values();

        $monthlyShifts = $user->dailyShifts()
            ->where('shift_date', '>=', Carbon::now()->subMonths(6)->startOfMonth())
            ->get(['shift_date', 'real_net_profit', 'total_km_gps']);

        $monthlyData = $monthlyShifts
            ->groupBy(fn ($shift) => $shift->shift_date->startOfMonth()->format('MMM'))
            ->map(fn ($group, $monthLabel) => [
                'month_start' => $monthLabel,
                'total_net_profit' => $group->sum('real_net_profit'),
                'total_km' => $group->sum('total_km_gps'),
            ])
            ->sortBy(fn ($entry) => Carbon::createFromFormat('MMM', $entry['month_start'])->month)
            ->values();

        // Recent fuel invoices
        $recentInvoices = $user->fuelInvoices()
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        return Inertia::render('Dashboard', [
            'summary' => [
                'total_shifts' => (int) ($summary->total_shifts ?? 0),
                'total_net_profit' => (float) ($summary->total_net_profit ?? 0),
                'total_km' => (float) ($summary->total_km ?? 0),
                'total_fuel_cost' => (float) ($summary->total_fuel_cost ?? 0),
                'total_trips' => (int) ($summary->total_trips ?? 0),
                'current_month_profit' => $currentMonthProfit,
            ],
            'recentShifts' => $recentShifts,
            'weeklyData' => $weeklyData,
            'monthlyData' => $monthlyData,
            'recentInvoices' => $recentInvoices,
        ]);
    }
}

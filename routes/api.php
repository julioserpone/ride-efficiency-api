<?php

use App\Http\Controllers\Api\AuthTokenController;
use App\Http\Controllers\Api\CountryController;
use App\Http\Controllers\Api\EnergyInvoiceController;
use App\Http\Controllers\Api\EnergyProductController;
use App\Http\Controllers\Api\RideTripController;
use App\Http\Controllers\Api\ShiftController;
use App\Http\Controllers\Api\StatsController;
use App\Http\Controllers\Api\VehicleController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Token issuance & revocation
    Route::post('/auth/token', [AuthTokenController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('api.v1.auth.token.store');
    Route::get('/auth/user', [AuthTokenController::class, 'show'])
        ->middleware('auth:sanctum')
        ->name('api.v1.auth.user');
    Route::delete('/auth/token', [AuthTokenController::class, 'destroy'])
        ->middleware('auth:sanctum')
        ->name('api.v1.auth.token.destroy');
});
Route::prefix('v1')->middleware(['auth:sanctum'])->group(function () {
    // Countries
    Route::get('/countries', [CountryController::class, 'index'])->name('api.v1.countries.index');

    // Shifts
    Route::get('/shifts', [ShiftController::class, 'index'])->name('api.v1.shifts.index');
    Route::post('/shifts', [ShiftController::class, 'store'])->name('api.v1.shifts.store');
    Route::get('/shifts/{shift}', [ShiftController::class, 'show'])->name('api.v1.shifts.show');
    Route::post('/shifts/{shift}/close', [ShiftController::class, 'close'])->name('api.v1.shifts.close');

    // Energy invoices
    Route::get('/energy-invoices', [EnergyInvoiceController::class, 'index'])->name('api.v1.energy-invoices.index');
    Route::post('/energy-invoices', [EnergyInvoiceController::class, 'store'])->name('api.v1.energy-invoices.store');
    Route::get('/energy-invoices/{energyInvoice}', [EnergyInvoiceController::class, 'show'])->name('api.v1.energy-invoices.show');
    Route::delete('/energy-invoices/{energyInvoice}', [EnergyInvoiceController::class, 'destroy'])->name('api.v1.energy-invoices.destroy');
    Route::patch('/energy-invoices/{energyInvoice}/review', [EnergyInvoiceController::class, 'review'])->name('api.v1.energy-invoices.review');

    // Country-specific energy products
    Route::get('/countries/{countryCode}/energy-products', [EnergyProductController::class, 'index'])
        ->name('api.v1.countries.energy-products.index');

    // Vehicles and individual ride profitability
    Route::get('/vehicles', [VehicleController::class, 'index'])->name('api.v1.vehicles.index');
    Route::post('/vehicles', [VehicleController::class, 'store'])->name('api.v1.vehicles.store');
    Route::get('/ride-trips', [RideTripController::class, 'index'])->name('api.v1.ride-trips.index');
    Route::post('/vehicles/{vehicle}/trips', [RideTripController::class, 'store'])->name('api.v1.vehicles.trips.store');
    Route::get('/ride-trips/{rideTrip}', [RideTripController::class, 'show'])->name('api.v1.ride-trips.show');

    // Stats & Metrics
    Route::get('/stats/weekly', [StatsController::class, 'weekly'])->name('api.v1.stats.weekly');
    Route::get('/stats/monthly', [StatsController::class, 'monthly'])->name('api.v1.stats.monthly');
    Route::get('/stats/summary', [StatsController::class, 'summary'])->name('api.v1.stats.summary');
    Route::get('/stats/efficiency', [StatsController::class, 'efficiency'])->name('api.v1.stats.efficiency');
});

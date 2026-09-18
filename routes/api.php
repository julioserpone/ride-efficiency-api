<?php

use App\Http\Controllers\Api\AuthTokenController;
use App\Http\Controllers\Api\FuelInvoiceController;
use App\Http\Controllers\Api\ShiftController;
use App\Http\Controllers\Api\StatsController;
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
    // Shifts
    Route::get('/shifts', [ShiftController::class, 'index'])->name('api.v1.shifts.index');
    Route::post('/shifts', [ShiftController::class, 'store'])->name('api.v1.shifts.store');
    Route::get('/shifts/{shift}', [ShiftController::class, 'show'])->name('api.v1.shifts.show');
    Route::post('/shifts/{shift}/close', [ShiftController::class, 'close'])->name('api.v1.shifts.close');

    // Fuel Invoices
    Route::get('/fuel-invoices', [FuelInvoiceController::class, 'index'])->name('api.v1.fuel-invoices.index');
    Route::post('/fuel-invoices', [FuelInvoiceController::class, 'store'])->name('api.v1.fuel-invoices.store');
    Route::get('/fuel-invoices/{fuelInvoice}', [FuelInvoiceController::class, 'show'])->name('api.v1.fuel-invoices.show');
    Route::delete('/fuel-invoices/{fuelInvoice}', [FuelInvoiceController::class, 'destroy'])->name('api.v1.fuel-invoices.destroy');

    // Stats & Metrics
    Route::get('/stats/weekly', [StatsController::class, 'weekly'])->name('api.v1.stats.weekly');
    Route::get('/stats/monthly', [StatsController::class, 'monthly'])->name('api.v1.stats.monthly');
    Route::get('/stats/summary', [StatsController::class, 'summary'])->name('api.v1.stats.summary');
    Route::get('/stats/efficiency', [StatsController::class, 'efficiency'])->name('api.v1.stats.efficiency');
});

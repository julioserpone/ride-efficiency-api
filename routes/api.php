<?php

use App\Http\Controllers\Api\ShiftController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['auth'])->group(function () {
    Route::get('/shifts', [ShiftController::class, 'index'])->name('api.v1.shifts.index');
    Route::post('/shifts', [ShiftController::class, 'store'])->name('api.v1.shifts.store');
    Route::get('/shifts/{shift}', [ShiftController::class, 'show'])->name('api.v1.shifts.show');
    Route::post('/shifts/{shift}/close', [ShiftController::class, 'close'])->name('api.v1.shifts.close');
});

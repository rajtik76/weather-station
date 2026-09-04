<?php

declare(strict_types=1);
use App\Http\Controllers\StoreMeasurementController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('sensor.auth')->group(function (): void {
    Route::post('/measurement', StoreMeasurementController::class);
});

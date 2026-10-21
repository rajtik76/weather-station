<?php

declare(strict_types=1);
use App\Http\Controllers\DiscordInteractionController;
use App\Http\Controllers\StoreMeasurementController;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('sensor.auth')->group(function (): void {
    Route::post('/measurement', StoreMeasurementController::class);
});

Route::post('/discord/interactions', DiscordInteractionController::class)
    ->middleware('discord.signature')
    ->withoutMiddleware(ThrottleRequests::class.':10,1');

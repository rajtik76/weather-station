<?php

declare(strict_types=1);

use App\Livewire\Dashboard;
use App\Models\Forecast;
use Database\Seeders\ForecastSeeder;
use Database\Seeders\MeasurementSeeder;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;

use function Pest\Laravel\seed;

it('seeds a forecast every seeded station shows on the dashboard', function (string $sensor): void {
    seed([MeasurementSeeder::class, ForecastSeeder::class]);

    Livewire::withQueryParams(['sensor' => $sensor])
        ->test(Dashboard::class)
        ->assertSee('Forecast · next 6 hours');
})->with(['sensor-001', 'sensor-002']);

it('seeds six hours in the shape the forecast service answers with', function (): void {
    // The record ends on the 11:50 slot: hourly forecasts from 12:00 two weeks back to 11:00, and 11:50's.
    $this->travelTo(Date::parse('2026-09-24 12:05:00', 'UTC'));
    seed([MeasurementSeeder::class, ForecastSeeder::class]);

    Forecast::query()->each(function (Forecast $forecast): void {
        expect(array_column($forecast->data, 'hours'))->toBe([1, 2, 3, 4, 5, 6]);

        foreach ($forecast->data as $horizon) {
            foreach (['temperature', 'humidity', 'pressure'] as $channel) {
                expect($horizon[$channel]['low'])->toBeLessThanOrEqual($horizon[$channel]['mid'])
                    ->and($horizon[$channel]['mid'])->toBeLessThanOrEqual($horizon[$channel]['high']);
            }

            foreach (['temperature', 'humidity'] as $channel) {
                expect(data_get($horizon, "base.{$channel}.low"))->toBeLessThanOrEqual(data_get($horizon, "base.{$channel}.mid"))
                    ->and(data_get($horizon, "base.{$channel}.mid"))->toBeLessThanOrEqual(data_get($horizon, "base.{$channel}.high"));
            }

            expect($horizon['humidity']['mid'])->toBeBetween(0, 100)
                ->and($horizon['rain_probability'])->toBeBetween(0, 1);
        }
    });

    expect(Forecast::query()->count())->toBe(2 * (14 * 24 + 1));
});

it('seeds forecasts old enough for the accuracy panel to score', function (): void {
    seed([MeasurementSeeder::class, ForecastSeeder::class]);

    Livewire::test(Dashboard::class)->assertSee('Accuracy · last 30 days');
});

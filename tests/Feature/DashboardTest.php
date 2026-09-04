<?php

declare(strict_types=1);

use App\Models\Measurement;
use App\ValueObject\MeasurementDataV1;
use Illuminate\Support\Facades\Date;

it('renders the readings stored in the database', function (): void {
    $at = now()->subHour();

    Measurement::factory()->create([
        'sensor_name' => 'bme280',
        'timestamp' => $at->getTimestamp(),
        'data' => (string) new MeasurementDataV1(temperature: 2150, humidity: 4800, pressure: 101300),
    ]);

    $this->get('/')
        ->assertOk()
        // Raw units are converted for display, keeping the sensor's two decimals:
        // 2150 -> 21,50 °C, 4800 -> 48,00 %, 101300 Pa -> 1013,0 hPa.
        ->assertSee('21,50')
        ->assertSee('48,00')
        ->assertSee('1 013,0')
        ->assertDontSee('Waiting for the first reading');
});

it('labels readings in Czech local time, not UTC', function (): void {
    // 12:00 UTC in July is 14:00 in Prague (CEST, UTC+2).
    Measurement::factory()->create([
        'timestamp' => Date::parse('2026-07-15 12:00:00', 'UTC')->getTimestamp(),
    ]);

    $this->travelTo(Date::parse('2026-07-15 13:00:00', 'UTC'));

    $this->get('/')
        ->assertOk()
        // Flux forces UTC on its formatters, so the stamp reaches the chart
        // pre-shifted and tagged `Z`.
        ->assertSee('2026-07-15T14:00:00Z')
        ->assertSee('15. 7. 2026 14:00');
});

it('labels readings in standard time outside the summer window', function (): void {
    // 12:00 UTC in January is 13:00 in Prague (CET, UTC+1).
    Measurement::factory()->create([
        'timestamp' => Date::parse('2026-01-15 12:00:00', 'UTC')->getTimestamp(),
    ]);

    $this->travelTo(Date::parse('2026-01-15 13:00:00', 'UTC'));

    $this->get('/')
        ->assertOk()
        ->assertSee('2026-01-15T13:00:00Z')
        ->assertSee('15. 1. 2026 13:00');
});

it('shows an empty state when nothing has been recorded', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertSee('Waiting for the first reading');
});

it('ignores readings older than the chart window', function (): void {
    Measurement::factory()->create([
        'timestamp' => now()->subDays(40)->getTimestamp(),
    ]);

    $this->get('/')->assertSee('Waiting for the first reading');
});

it('credits the author in the footer', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertSee('Vladislav Rajtmajer')
        ->assertSee((string) now()->year)
        ->assertSee('https://github.com/rajtik76');
});

<?php

declare(strict_types=1);

use App\Livewire\Forecast as ForecastPage;
use App\Models\Forecast;
use App\Models\Measurement;
use App\Models\Sensor;
use App\ValueObject\MeasurementDataV1;
use App\ValueObject\MeasurementDataV3;
use App\ValueObject\NoiseWindow;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;

/**
 * @param  array{0: float, 1: float, 2: float}|null  $base
 * @return array<string, mixed>
 */
function scoresHorizon(int $hours, float $low, float $mid, float $high, float $rain = 0.0, ?array $base = null): array
{
    return [
        'hours' => $hours,
        'temperature' => ['low' => $low, 'mid' => $mid, 'high' => $high],
        'humidity' => ['low' => 70.0, 'mid' => 75.0, 'high' => 80.0],
        'pressure' => ['low' => 976.0, 'mid' => 976.5, 'high' => 977.0],
        'rain_probability' => $rain,
        ...($base === null ? [] : ['base' => [
            'temperature' => ['low' => $base[0], 'mid' => $base[1], 'high' => $base[2]],
            'humidity' => ['low' => 68.0, 'mid' => 75.0, 'high' => 82.0],
        ]]),
    ];
}

function scoresReading(Sensor $sensor, int $timestamp, int $temperature): void
{
    Measurement::factory()->for($sensor)->create([
        'timestamp' => $timestamp,
        'data' => (string) new MeasurementDataV1(temperature: $temperature, humidity: 5000, pressure: 97000),
    ]);
}

/** A V3 window at 12 °C whose spectrum RainDetector hears as rain, or as a dry street. */
function scoresListened(Sensor $sensor, int $timestamp, bool $raining): void
{
    $bands = array_fill(0, 26, 3000);
    $bands[15] = 4000;
    $bands[17] = 4000;
    $bands[16] = $raining ? 4500 : 4000;
    $bands[25] = $raining ? 5500 : 3000;

    Measurement::factory()->for($sensor)->v3()->create([
        'timestamp' => $timestamp,
        'data' => (string) new MeasurementDataV3(
            temperature: 1200, humidity: 8000, pressure: 97000,
            temperatureMin: 1190, temperatureMax: 1210,
            humidityMin: 7900, humidityMax: 8100,
            pressureMin: 96990, pressureMax: 97010,
            samples: 20,
            noise: new NoiseWindow(seconds: 600, laeq: 6000, lamax: 7000, la10: 6200, la90: 5500, bands: $bands),
        ),
    ]);
}

beforeEach(function (): void {
    $this->travelTo(Date::parse('2026-09-24 12:00:00', 'UTC'));
});

it('shows the scores without a current forecast to sit beside', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp();
    scoresReading($sensor, $issued, 1200);
    scoresReading($sensor, $issued + 3600, 1300);
    scoresReading($sensor, $issued + 7200, 1350);
    // Two hours older than the newest reading: the forecast is gone.
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued, 'data' => [scoresHorizon(1, 12.0, 12.8, 13.5)]]);

    $this->get(route('forecast'))
        ->assertOk()
        ->assertSee('No forecast from the current readings yet')
        ->assertSeeInOrder(['Verdict by horizon', '1 h', '+80 %', '100 %'])
        ->assertDontSee('No forecast has come true yet');
});

it('sets the base model\'s range width beside the forecast shown', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp();
    scoresReading($sensor, $issued, 1200);
    scoresReading($sensor, $issued + 3600, 1300);
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued, 'data' => [scoresHorizon(1, 12.0, 12.8, 13.5, base: [12.0, 12.4, 12.9])]]);
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued + 3600]);

    $this->get(route('forecast'))
        ->assertOk()
        ->assertSeeInOrder(['1 h', '+80 %', '100 %', '1,5 °C', 'base 0,9']);
});

it('offers only the horizons that have come true, an hour ahead first shown', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp();
    scoresReading($sensor, $issued, 1200);
    scoresReading($sensor, $issued + 3600, 1300);
    scoresReading($sensor, $issued + 7200, 1500);
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued, 'data' => [scoresHorizon(1, 12.0, 12.8, 13.5), scoresHorizon(2, 12.5, 13.0, 14.0)]]);
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued + 7200]);

    $page = Livewire::test(ForecastPage::class)->assertSet('horizon', 1);

    expect($page->html())
        ->toMatch('/wire:click="\$set\(\'horizon\', 1\)"\s+aria-pressed="true"/')
        ->toMatch('/wire:click="\$set\(\'horizon\', 2\)"\s+aria-pressed="false"/')
        ->not->toContain("\$set('horizon', 3)")
        ->not->toContain("\$set('horizon', 6)");

    $page->call('$set', 'horizon', 2)->assertSet('horizon', 2);

    expect($page->html())->toMatch('/wire:click="\$set\(\'horizon\', 2\)"\s+aria-pressed="true"/');
});

it('moves the choice onto a horizon still scored when the scores change under it', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp();
    scoresReading($sensor, $issued, 1200);
    scoresReading($sensor, $issued + 3600, 1300);
    scoresReading($sensor, $issued + 7200, 1500);
    $both = Forecast::factory()->for($sensor)->create(['issued_at' => $issued, 'data' => [scoresHorizon(1, 12.0, 12.8, 13.5), scoresHorizon(2, 12.5, 13.0, 14.0)]]);
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued + 7200]);

    $page = Livewire::test(ForecastPage::class)->set('horizon', 2)->assertSet('horizon', 2);

    $both->update(['data' => [scoresHorizon(1, 12.0, 12.8, 13.5)]]);
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued + 7800]);

    $page->call('$refresh')->assertSet('horizon', 1)->assertSee('+80 %');
});

it('says which side of the rain score it has no case for', function (array $rainingSlots, string $none, string $given): void {
    $sensor = Sensor::factory()->create();
    $issued = Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp();

    foreach (range(0, 6) as $slot) {
        scoresListened($sensor, $issued + $slot * 600, in_array($slot, $rainingSlots, true));
    }

    Forecast::factory()->for($sensor)->create(['issued_at' => $issued, 'data' => [scoresHorizon(1, 11.0, 12.0, 13.0, 0.47)]]);
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued + 3600]);

    $this->get(route('forecast'))
        ->assertOk()
        ->assertSeeInOrder(['Rain chance', 'When it rained', ...$given === 'rained' ? ['47 %', 'When it did not', $none] : [$none, 'When it did not', '47 %']]);
})->with([
    // It rained within every hour scored: there is no dry case to average.
    'no dry spell' => [[2], 'no dry spell', 'rained'],
    'no rain heard' => [[], 'no rain heard', 'dry'],
]);

it('holds the verdict back until a day of forecasts has come true', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp();
    scoresReading($sensor, $issued, 1200);
    scoresReading($sensor, $issued + 3600, 1300);
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued, 'data' => [scoresHorizon(1, 12.0, 12.8, 13.5)]]);

    $this->get(route('forecast'))
        ->assertOk()
        ->assertSee('Too early to tell')
        ->assertDontSee('target 80');
});

it('says when the forecast is not yet fitted to the station', function (): void {
    $sensor = Sensor::factory()->create();
    scoresReading($sensor, now()->getTimestamp(), 1300);
    Forecast::factory()->for($sensor)->create(['issued_at' => now()->getTimestamp(), 'corrected' => false]);

    $this->get(route('forecast'))
        ->assertOk()
        ->assertSee('not yet fitted to this station');
});

it('dates the forecast by when it arrived, not by the window it starts from', function (): void {
    // The 08:10:20 upload carries window 08:00-08:10.
    $this->travelTo(Date::parse('2026-09-24 08:10:21', 'UTC'));
    $sensor = Sensor::factory()->create();
    Measurement::factory()->for($sensor)->create(['timestamp' => now()->subSeconds(34)->getTimestamp()]);
    Forecast::factory()->for($sensor)->create(['issued_at' => now()->subMinutes(10)->startOfMinute()->getTimestamp()]);

    $this->get(route('forecast'))
        ->assertOk()
        ->assertSee('made 24.9.2026 10:10')
        ->assertDontSee('made 24.9.2026 10:00');
});

<?php

declare(strict_types=1);

use App\Livewire\Forecast as ForecastPage;
use App\Models\Forecast;
use App\Models\Measurement;
use App\Models\Sensor;
use App\ValueObject\MeasurementDataV1;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;

/**
 * @return array<string, mixed>
 */
function pageHorizon(int $hours, float $low, float $mid, float $high): array
{
    return [
        'hours' => $hours,
        'temperature' => ['low' => $low, 'mid' => $mid, 'high' => $high],
        'humidity' => ['low' => 70.0, 'mid' => 75.0, 'high' => 80.0],
        'pressure' => ['low' => 976.0, 'mid' => 976.5, 'high' => 977.0],
        'rain_probability' => 0.1,
    ];
}

function pageReading(Sensor $sensor, int $timestamp, int $temperature): void
{
    Measurement::factory()->for($sensor)->create([
        'timestamp' => $timestamp,
        'data' => (string) new MeasurementDataV1(temperature: $temperature, humidity: 5000, pressure: 97000),
    ]);
}

/** A forecast at 08:00 UTC scored 1 and 2 h on: misses 0,2 vs the guess's 1,0 (+80 %), then 2,0 vs 3,0 (+33 %). */
function scoredStation(): Sensor
{
    $sensor = Sensor::factory()->create();
    $issued = Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp();
    pageReading($sensor, $issued, 1200);
    pageReading($sensor, $issued + 3600, 1300);
    pageReading($sensor, $issued + 7200, 1500);
    Forecast::factory()->for($sensor)->create([
        'issued_at' => $issued,
        'data' => [pageHorizon(1, 12.0, 12.8, 13.5), pageHorizon(2, 12.5, 13.0, 14.0)],
    ]);
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued + 7200]);

    return $sensor;
}

beforeEach(function (): void {
    $this->travelTo(Date::parse('2026-09-24 10:05:00', 'UTC'));
});

it('waits for forecasts on an empty station without polling', function (): void {
    $html = $this->get(route('forecast'))
        ->assertOk()
        ->assertSee('class="instrument', false)
        ->assertSee('Can it beat “nothing changes”?')
        ->assertSee('Too early to tell')
        ->assertSee('No forecast from the current readings yet')
        ->assertSee('No forecast has come true yet')
        ->assertSee('No forecast stored yet')
        ->assertDontSee('data-accuracy-chart', false)
        ->getContent();

    expect($html)->not->toContain('wire:poll')
        ->toMatch('#href="[^"]*/forecast"\s+aria-current="page"#');
});

it('scores every horizon side by side', function (): void {
    scoredStation();

    $this->get(route('forecast'))
        ->assertOk()
        ->assertSeeInOrder(['Verdict by horizon', '1 h', '+80 %', '100 %', '1,5 °C', '2 h', '+33 %', '0 %', '1,5 °C'])
        ->assertSee('style="width: 80%"', false)
        ->assertDontSee('No forecast has come true yet');
});

it('charts the verdict\'s horizon in detail, the longest scored until it has come true', function (): void {
    scoredStation();

    $page = Livewire::test(ForecastPage::class)->assertSet('horizon', 2);

    expect($page->html())
        ->toContain('data-accuracy-chart="days"')
        ->toContain('data-accuracy-chart="widths"')
        ->toContain('data-accuracy-chart="hours"')
        ->toMatch('/wire:click="\$set\(\'horizon\', 2\)"\s+aria-pressed="true"/')
        ->toContain('data-accuracy-rows="'.e(json_encode([['24.9.2026', 1, 33.0, 2.0, 3.0, 0.0, 1.5, null, null, null, null, null, null]], JSON_THROW_ON_ERROR)).'"');

    $page->set('horizon', 1)->assertSet('horizon', 1)->assertSee('1 h ahead');
    expect($page->get('score')['hours'])->toBe(1);

    $page->set('horizon', 5)->assertSet('horizon', 2);
});

it('draws the current forecast while it starts from the newest reading', function (): void {
    $sensor = Sensor::factory()->create();
    pageReading($sensor, now()->getTimestamp(), 1300);
    Forecast::factory()->for($sensor)->create([
        'issued_at' => now()->getTimestamp(),
        'data' => [pageHorizon(1, 12.3, 13.8, 15.3)],
    ]);

    $this->get(route('forecast'))
        ->assertOk()
        // 10:05 UTC is 12:05 in Prague in September.
        ->assertSeeInOrder(['Current forecast', 'made 24.9.2026 12:05', '13:05', '13,8', '12,3-15,3'])
        ->assertDontSee('No forecast from the current readings yet');
});

it('lists every change of model and correction, newest first, a switch back included', function (): void {
    $sensor = Sensor::factory()->create();

    foreach ([
        ['2026-09-20 06:00:00', '2026-09-18T12:00:00+00:00', null],
        ['2026-09-22 06:00:00', '2026-09-18T12:00:00+00:00', 1],
        ['2026-09-24 06:00:00', '2026-09-24T08:40:43+00:00', 2],
        // Rolled back to the older model, the correction unchanged.
        ['2026-09-24 07:00:00', '2026-09-18T12:00:00+00:00', 2],
    ] as [$issued, $model, $correction]) {
        Forecast::factory()->for($sensor)->create([
            'issued_at' => Date::parse($issued, 'UTC')->getTimestamp(),
            'model' => $model,
            'correction' => $correction,
        ]);
    }

    Forecast::factory()->create(['model' => 'elsewhere']);

    $this->get(route('forecast'))
        ->assertOk()
        ->assertSeeInOrder([
            'Changelog',
            '24.9.2026', 'The model trained', '18.9.2026 14:00', 'forecasts from here on',
            '24.9.2026', 'The model trained', '24.9.2026 10:40', 'forecasts from here on',
            '24.9.2026', 'Correction 2 applies from here on',
            '22.9.2026', 'Correction 1 applies from here on',
            '20.9.2026', 'The model trained', '18.9.2026 14:00', 'forecasts from here on',
        ])
        ->assertDontSee('elsewhere');
});

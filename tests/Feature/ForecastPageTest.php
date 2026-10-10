<?php

declare(strict_types=1);

use App\Livewire\Forecast as ForecastPage;
use App\Models\Forecast;
use App\Models\Measurement;
use App\Models\Sensor;
use App\ValueObject\DayScore;
use App\ValueObject\MeasurementDataV1;
use App\ValueObject\ScoreFigures;
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

it('keeps the stored weather model off the page', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp();
    pageReading($sensor, $issued, 1200);
    pageReading($sensor, $issued + 3600, 1300);
    Forecast::factory()->for($sensor)->create([
        'issued_at' => $issued,
        'data' => [[...pageHorizon(1, 12.0, 12.8, 13.5), 'nwp' => ['temperature' => 13.5]]],
    ]);

    $this->get(route('forecast'))
        ->assertOk()
        ->assertSeeInOrder(['Verdict by horizon', '1 h', '+80 %'])
        ->assertDontSee('Weather model')
        ->assertDontSee('Open-Meteo');
});

it('names the light experiment by its version in the comparison graphs', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp();
    pageReading($sensor, $issued, 1200);
    pageReading($sensor, $issued + 3600, 1300);
    Forecast::factory()->for($sensor)->create([
        'issued_at' => $issued,
        'data' => [[
            ...pageHorizon(1, 12.0, 12.8, 13.5),
            'base' => ['temperature' => ['low' => 12.0, 'mid' => 12.4, 'high' => 12.9], 'humidity' => ['low' => 68.0, 'mid' => 75.0, 'high' => 82.0]],
            'experiment' => ['version' => 'light-v1', 'temperature' => ['low' => 12.5, 'mid' => 12.9, 'high' => 13.5]],
        ]],
    ]);

    $html = $this->get(route('forecast'))
        ->assertSee('light-v1 is the newest light experiment; it is shown wherever it leads the model race.')
        ->assertSee('data-accuracy-experiment="light-v1"', false)
        ->assertDontSee('VEML prototype')
        ->getContent();

    preg_match('/data-accuracy-chart="days"\s+data-accuracy-rows="([^"]*)"/', $html ?: '', $matches);
    $days = json_decode(html_entity_decode($matches[1] ?? '[]'), true, flags: JSON_THROW_ON_ERROR);
    expect($days[0]['experiment'])->toMatchArray(['skill' => 90.0, 'error' => 0.1, 'inRange' => 100.0]);
});

it('keeps the shown and base history when the prototype starts later', function (): void {
    $sensor = Sensor::factory()->create();
    $older = Date::parse('2026-09-10 08:00:00', 'UTC')->getTimestamp();
    $recent = Date::parse('2026-09-24 09:00:00', 'UTC')->getTimestamp();
    foreach ([$older, $recent] as $issued) {
        pageReading($sensor, $issued, 1200);
        pageReading($sensor, $issued + 3600, 1300);
        $horizon = [
            ...pageHorizon(1, 12.0, 12.8, 13.5),
            'base' => ['temperature' => ['low' => 12.0, 'mid' => 12.4, 'high' => 12.9], 'humidity' => ['low' => 68.0, 'mid' => 75.0, 'high' => 82.0]],
        ];
        if ($issued === $recent) {
            $horizon['experiment'] = ['version' => 'light-v1', 'temperature' => ['low' => 12.5, 'mid' => 12.9, 'high' => 13.5]];
        }
        Forecast::factory()->for($sensor)->create(['issued_at' => $issued, 'data' => [$horizon]]);
    }

    $html = $this->get(route('forecast'))
        ->assertSee('light-v1 runs since 24.9.2026, not long enough for this period.')
        ->assertSeeInOrder(['>Today<', '>Yesterday<', '>7 days<', '>30 days<'], false)
        ->getContent();
    preg_match_all('/data-accuracy-chart="([^"]+)"\s+data-accuracy-rows="([^"]*)"/', $html ?: '', $matches, PREG_SET_ORDER);
    $charts = [];
    foreach ($matches as $match) {
        $charts[$match[1]] = json_decode(html_entity_decode($match[2]), true, flags: JSON_THROW_ON_ERROR);
    }

    foreach (['days', 'widths'] as $key) {
        expect($charts[$key])->toHaveCount(15);
        expect($charts[$key][0])->toEqual([
            'date' => '10.9.2026',
            'shown' => ['count' => 1, 'skill' => 80.0, 'error' => 0.2, 'naive' => 1.0, 'inRange' => 100.0, 'width' => 1.5],
            'base' => ['count' => 1, 'skill' => 40.0, 'error' => 0.6, 'naive' => 1.0, 'inRange' => 0.0, 'width' => 0.9],
            'modelTookOver' => null,
            'correctionTookOver' => null,
            'experiment' => null,
        ]);
        expect($charts[$key][14]['date'])->toBe('24.9.2026');
        expect($charts[$key][14]['experiment'])->toEqual(['count' => 1, 'skill' => 90.0, 'error' => 0.1, 'naive' => 1.0, 'inRange' => 100.0, 'width' => 1.0]);
    }
    $base = ['count' => 1, 'inRange' => 0.0, 'error' => 0.6, 'worst' => 0.6, 'bias' => 0.6, 'experiment' => null, 'base' => null];
    expect($charts['hours']['month'][11])->toEqual(['count' => 1, 'inRange' => 100.0, 'error' => 0.2, 'worst' => 0.2, 'bias' => 0.2, 'experiment' => null, 'base' => $base]);
    expect($charts['hours']['month'][12]['experiment'])->toBeNull();
    expect($charts['hours']['week'][12]['experiment'])->toEqual(['count' => 1, 'inRange' => 100.0, 'error' => 0.1, 'worst' => 0.1, 'bias' => 0.1, 'experiment' => null, 'base' => null]);
    expect($charts['hours']['today'])->not->toBeEmpty();
});

it('charts an hour ahead in detail, the longest scored when the chosen horizon has not come true', function (): void {
    scoredStation();

    $page = Livewire::test(ForecastPage::class)->assertSet('horizon', 1)->assertSee('1 h ahead');
    expect($page->get('score')['hours'])->toBe(1);

    $page->set('horizon', 2)->assertSet('horizon', 2);

    expect($page->html())
        ->toContain('data-accuracy-chart="days"')
        ->toContain('data-accuracy-chart="widths"')
        ->toContain('data-accuracy-chart="hours"')
        ->toMatch('/wire:click="\$set\(\'horizon\', 2\)"\s+aria-pressed="true"/')
        ->toContain('data-accuracy-rows="'.e(json_encode([new DayScore('24.9.2026', new ScoreFigures(1, 33.0, 2.0, 3.0, 0.0, 1.5))], JSON_THROW_ON_ERROR)).'"');

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
        // 10:05 UTC is 12:05 in Prague in September; 13:00 is 55 of the 60 minutes from the reading to the first horizon.
        ->assertSeeInOrder(['Current forecast', 'made 24.9.2026 12:05', 'range this hour', '13:00', '13,7', '12,4-15,1'])
        ->assertDontSee('No forecast from the current readings yet');
});

it('names the model behind each hour of the current forecast', function (): void {
    $sensor = Sensor::factory()->create();
    pageReading($sensor, now()->getTimestamp(), 1300);
    Forecast::factory()->for($sensor)->create([
        'issued_at' => now()->getTimestamp(),
        'data' => [[...pageHorizon(1, 12.3, 13.8, 15.3), 'shown_by' => 'light-v6']],
    ]);

    $this->get(route('forecast'))
        ->assertSeeInOrder(['Current forecast', '13:00', '13,7', 'by', 'light-v6', 'Model race']);
});

it('ranks the race by each part of the day and charts every model\'s points', function (): void {
    $sensor = Sensor::factory()->create();
    racedReading($sensor, '2026-09-23 07:00:00', 1200);
    racedForecast($sensor, '2026-09-23 06:00:00', 1, ['correction' => 13.0, 'light-v6' => 12.5, 'light-v5' => 11.0, 'base' => 14.0]);

    $html = $this->get(route('forecast'))
        ->assertSeeInOrder(['Model race', 'Morning', 'Points by day', 'Standings', 'light-v6', '· shown', '0,50', '1', 'correction', '1,00', 'light-v5', '1,00', 'base', '2,00'])
        ->getContent();

    preg_match('/data-accuracy-chart="race"\s+data-accuracy-rows="([^"]*)"/', $html ?: '', $matches);
    $payload = json_decode(html_entity_decode($matches[1] ?? '{}'), true, flags: JSON_THROW_ON_ERROR);
    expect($payload['entrants'])->toBe(['base', 'correction', 'light-v5', 'light-v6'])
        ->and($payload['days'])->toHaveCount(14)
        ->and($payload['days'][13])->toEqual(['date' => '23.9.2026', 'points' => ['morning' => ['correction' => 1.0, 'light-v6' => 0.5, 'light-v5' => 1.0, 'base' => 2.0]]]);
});

it('waits for a whole scored day before it charts the race', function (): void {
    $sensor = Sensor::factory()->create();
    racedReading($sensor, '2026-09-24 07:00:00', 1200);
    racedForecast($sensor, '2026-09-24 06:00:00', 1, ['correction' => 13.0, 'light-v6' => 12.5, 'light-v5' => 11.0, 'base' => 14.0]);

    $this->get(route('forecast'))
        ->assertSee('No day scored yet')
        ->assertDontSee('data-accuracy-chart="race"', false);
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

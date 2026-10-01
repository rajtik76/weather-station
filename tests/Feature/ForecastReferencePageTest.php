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
 * One forecast at 08:00 UTC on 24.9., 12 °C then and 13 °C an hour on,
 * scored one hour ahead against the band given.
 *
 * @param  array{0: float, 1: float, 2: float}  $band
 */
function scoredOneHourAhead(Sensor $sensor, array $band): void
{
    $issued = Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp();

    foreach ([[$issued, 1200], [$issued + 3600, 1300]] as [$timestamp, $temperature]) {
        Measurement::factory()->for($sensor)->create([
            'timestamp' => $timestamp,
            'data' => (string) new MeasurementDataV1(temperature: $temperature, humidity: 5000, pressure: 97000),
        ]);
    }

    Forecast::factory()->for($sensor)->create(['issued_at' => $issued, 'data' => [[
        'hours' => 1,
        'temperature' => ['low' => $band[0], 'mid' => $band[1], 'high' => $band[2]],
        'humidity' => ['low' => 45.0, 'mid' => 50.0, 'high' => 55.0],
        'pressure' => ['low' => 969.5, 'mid' => 970.0, 'high' => 970.5],
        'rain_probability' => 0.0,
    ]]]);
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued + 3600]);
}

beforeEach(function (): void {
    $this->travelTo(Date::parse('2026-09-24 10:05:00', 'UTC'));
});

it('sets the reference station\'s skill beside the balcony\'s, by horizon and by day', function (): void {
    // The balcony misses by 0,2 against the guess's 1,0 (+80 %), the reference not at all (+100 %).
    scoredOneHourAhead(Sensor::factory()->create(), [12.0, 12.8, 13.5]);
    scoredOneHourAhead(Sensor::reference(), [12.5, 13.0, 13.5]);

    $html = $this->get(route('forecast'))
        ->assertOk()
        ->assertSeeInOrder(['Verdict by horizon', 'Mikulka', '1 h', '+80 %', '+100 %'])
        ->assertSee('the same model run every night on the day before at the ČHMÚ station Plzeň-Mikulka')
        ->getContent();

    expect($html)->toContain('data-reference-rows="'.e(json_encode([100.0], JSON_THROW_ON_ERROR)).'"');
});

it('leaves the reference out until its job has run', function (): void {
    scoredOneHourAhead(Sensor::factory()->create(), [12.0, 12.8, 13.5]);

    $page = Livewire::test(ForecastPage::class)
        ->assertDontSee('<th scope="col">Mikulka</th>', false)
        ->assertDontSee('the same model run every night on the day before');

    expect($page->html())->toContain('data-reference-rows="'.e(json_encode([null], JSON_THROW_ON_ERROR)).'"');
});

it('never offers the reference station as a sensor of its own', function (): void {
    $balcony = Sensor::factory()->create();
    $reference = Sensor::reference();

    Livewire::withQueryParams(['sensor' => $reference->slug])
        ->test(ForecastPage::class)
        ->assertSet('sensor', $balcony->slug)
        ->assertDontSee('Choose a sensor');
});

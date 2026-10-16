<?php

declare(strict_types=1);

use App\Livewire\Charts;
use App\Livewire\Comparison;
use App\Livewire\Overview;
use App\Models\Measurement;
use App\Models\ReferenceTemperature;
use App\Models\Sensor;
use App\Models\TemperatureCalibration;
use App\Queries\TemperatureCalibrator;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

it('plots the difference from Mikulka even when only reference data exists', function (): void {
    $this->travelTo(Date::parse('2026-10-16 12:00:00', 'UTC'));
    $slot = strtotime('2026-10-16T07:00:00Z');
    ReferenceTemperature::factory()->create(['timestamp' => $slot, 'temperature' => 10.0]);
    Http::preventStrayRequests();

    $page = Livewire::test(Comparison::class);
    $rows = $page->get('comparisonRows');

    expect($rows)->toHaveCount(144)
        ->and(array_values(array_filter($rows, fn (array $row): bool => $row[2] !== null))[0])
        ->toBe([1792141200000, null, 10.0, null]);
    $page->assertSee('Mikulka − shade estimate')->assertSee('No matched observations for this day yet.')
        ->assertSee('No matched corrected observations yet.')->assertDontSee('wire:poll', false);
    Http::assertNothingSent();
});

it('normalises dates from the URL and subsequent property updates', function (string $date, string $expected): void {
    $this->travelTo(Date::parse('2026-10-16 12:00:00', 'UTC'));

    Livewire::withQueryParams(['date' => $date])->test(Comparison::class)->assertSet('date', $expected);
    Livewire::test(Comparison::class)->set('date', $date)->assertSet('date', $expected);
})->with([
    'too old' => ['2026-01-01', '2026-09-16'],
    'tomorrow' => ['2026-10-17', '2026-10-16'],
    'invalid text' => ['invalid', '2026-10-16'],
    'invalid calendar date' => ['2026-09-31', '2026-10-16'],
    'earliest date' => ['2026-09-16', '2026-09-16'],
]);

it('moves between calendar days and stops at both date limits', function (): void {
    $this->travelTo(Date::parse('2026-10-16 12:00:00', 'UTC'));

    Livewire::test(Comparison::class)->call('moveDay', 1)->assertSet('date', '2026-10-16')
        ->call('moveDay', -1)->assertSet('date', '2026-10-15')
        ->set('date', '2026-09-16')->call('moveDay', -1)->assertSet('date', '2026-09-16');
});

it('uses local calendar days including daylight saving transitions', function (string $today, string $selected, int $slots): void {
    $this->travelTo(Date::parse($today, 'UTC'));

    $page = Livewire::withQueryParams(['date' => $selected])->test(Comparison::class);

    expect($page->get('comparisonRows'))->toHaveCount($slots);
})->with([
    'spring' => ['2026-03-30 12:00:00', '2026-03-29', 138],
    'autumn' => ['2026-10-26 12:00:00', '2026-10-25', 150],
]);

it('keeps the thirty day summary when changing the chart date and scopes it to the chosen sensor', function (): void {
    $this->travelTo(Date::parse('2026-10-16 08:00:00', 'UTC'));
    $sensor = Sensor::factory()->create();
    $other = Sensor::factory()->create();
    $end = strtotime('2026-10-16T07:00:00Z');
    TemperatureCalibration::factory()->for($sensor)->create();
    correctionHistory($sensor, $end);
    ReferenceTemperature::factory()->create(['timestamp' => $end, 'temperature' => 10.0]);
    $page = Livewire::test(Comparison::class);
    $summary = $page->get('summary');

    $page->set('date', '2026-10-15');

    expect($summary['count'])->toBe(1)
        ->and($page->get('summary'))->toBe($summary);
    $page->set('sensor', $other->slug);
    expect($page->get('summary')['count'])->toBe(0);
});

it('includes only the last thirty days in deviation figures', function (): void {
    $this->travelTo(Date::parse('2026-10-16 08:00:00', 'UTC'));
    $sensor = Sensor::factory()->create();
    $old = now()->subDays(31)->getTimestamp();
    Measurement::factory()->for($sensor)->create(['timestamp' => $old]);
    ReferenceTemperature::factory()->create(['timestamp' => $old, 'temperature' => 10.0]);

    Livewire::test(Comparison::class)->assertSet('summary.unavailable', 0);
});

it('shows the same current correction on overview and in the chart payload', function (): void {
    $this->travelTo(Date::parse('2026-10-16 07:00:00', 'UTC'));
    $sensor = Sensor::factory()->create();
    TemperatureCalibration::factory()->for($sensor)->create();
    correctionHistory($sensor, now()->getTimestamp());

    Livewire::test(Overview::class)->assertSee('11,10')->assertSee('data-shade-trace', false);
    $rows = Livewire::test(Charts::class)->get('correctedRows');

    expect($rows[array_key_last($rows)][2])->toEqualWithDelta(11.1, 0.001);
});

it('keeps a missing current correction explicit on overview', function (): void {
    $this->travelTo(Date::parse('2026-10-16 07:00:00', 'UTC'));
    $sensor = Sensor::factory()->create();
    TemperatureCalibration::factory()->for($sensor)->create();
    correctionHistory($sensor, now()->getTimestamp(), missingAt: 6);

    Livewire::test(Overview::class)->assertSee('Unavailable for this reading.')->assertDontSee('11,10');
});

it('draws a retrained model only on its own validation days and scores it there', function (): void {
    $data = heatingCalibrationData();
    $candidate = resolve(TemperatureCalibrator::class)->calibrate($data['sensor']->id, $data['from'], $data['validateFrom'], $data['until'], $data['features']);
    $this->travelTo(Date::parse('2026-10-16 12:00:00', 'UTC'));

    $validationDay = Livewire::withQueryParams(['date' => '2026-10-09'])->test(Comparison::class);
    $laterDay = Livewire::withQueryParams(['date' => '2026-10-12'])->test(Comparison::class);
    $summary = $validationDay->get('candidateSummary');

    expect(array_filter(array_column($validationDay->get('comparisonRows'), 3), fn (?float $value): bool => $value !== null))->toHaveCount(144)
        ->and(array_filter(array_column($laterDay->get('comparisonRows'), 3), fn (?float $value): bool => $value !== null))->toBeEmpty()
        ->and($summary['candidate']['count'])->toBe(432)
        ->and($summary['measured']['count'])->toBe(432)
        ->and($summary['estimated']['count'])->toBe(0)
        ->and($summary['candidate']['mae'])->toBeLessThan(0.01)
        ->and($summary['measured']['mae'])->toBeGreaterThan(0.5)
        ->and($summary['estimated']['mae'])->toBeNull();
    $validationDay->assertSee('Mikulka − retrained model #'.$candidate->id.' · accepted')
        ->assertSee('Retrained model on its validation days')->assertSee('validated 8.10.2026 - 10.10.2026');
    $laterDay->assertDontSee('Mikulka − retrained model');
});

it('leaves training readings out of the first validation slot', function (): void {
    $data = heatingCalibrationData();
    resolve(TemperatureCalibrator::class)->calibrate($data['sensor']->id, $data['from'], $data['validateFrom'], $data['until'], $data['features']);
    $this->travelTo(Date::parse('2026-10-16 12:00:00', 'UTC'));

    $rows = Livewire::withQueryParams(['date' => '2026-10-08'])->test(Comparison::class)->get('comparisonRows');

    expect($rows[0][3])->toBeNull()
        ->and($rows[1][3])->not->toBeNull();
});

it('hides the retrained legend on a validation day without its estimates', function (): void {
    $data = heatingCalibrationData();
    resolve(TemperatureCalibrator::class)->calibrate($data['sensor']->id, $data['from'], $data['validateFrom'], $data['until'], $data['features']);
    Measurement::query()->where('timestamp', '>=', strtotime('2026-10-08T21:55:00Z'))->delete();
    $this->travelTo(Date::parse('2026-10-16 12:00:00', 'UTC'));

    Livewire::withQueryParams(['date' => '2026-10-09'])->test(Comparison::class)
        ->assertDontSee('Mikulka − retrained model')->assertSee('Retrained model on its validation days');
});

it('reuses the retrained model summary instead of recomputing it on every request', function (): void {
    $data = heatingCalibrationData();
    resolve(TemperatureCalibrator::class)->calibrate($data['sensor']->id, $data['from'], $data['validateFrom'], $data['until'], $data['features']);
    $this->travelTo(Date::parse('2026-10-16 12:00:00', 'UTC'));
    $summary = Livewire::test(Comparison::class)->get('candidateSummary');

    ReferenceTemperature::query()->delete();

    expect(Livewire::test(Comparison::class)->get('candidateSummary'))->toBe($summary);
});

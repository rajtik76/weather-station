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
use App\ValueObject\LocalTime;
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

    expect($rows)->toHaveCount(91)
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

it('draws a retrained model only on its own validation days', function (): void {
    $data = heatingCalibrationData();
    $candidate = resolve(TemperatureCalibrator::class)->calibrate($data['sensor']->id, $data['from'], $data['validateFrom'], $data['until'], $data['features']);
    $this->travelTo(Date::parse('2026-10-16 12:00:00', 'UTC'));

    $validationDay = Livewire::withQueryParams(['date' => '2026-10-09'])->test(Comparison::class);
    $laterDay = Livewire::withQueryParams(['date' => '2026-10-12'])->test(Comparison::class);

    expect(array_filter(array_column($validationDay->get('comparisonRows'), 3), fn (?float $value): bool => $value !== null))->toHaveCount(144)
        ->and(array_filter(array_column($laterDay->get('comparisonRows'), 3), fn (?float $value): bool => $value !== null))->toBeEmpty();
    $validationDay->assertDontSee('Retrained model on its validation days');
    $laterDay->assertDontSee('data-strip="improvement"', false);
});

it('leaves training readings out of the first validation slot', function (): void {
    $data = heatingCalibrationData();
    resolve(TemperatureCalibrator::class)->calibrate($data['sensor']->id, $data['from'], $data['validateFrom'], $data['until'], $data['features']);
    $this->travelTo(Date::parse('2026-10-16 12:00:00', 'UTC'));

    $rows = Livewire::withQueryParams(['date' => '2026-10-08'])->test(Comparison::class)->get('comparisonRows');

    expect($rows[0][3])->toBeNull()
        ->and($rows[1][3])->not->toBeNull();
});

it('hides the improvement chart on a validation day without its estimates', function (): void {
    $data = heatingCalibrationData();
    resolve(TemperatureCalibrator::class)->calibrate($data['sensor']->id, $data['from'], $data['validateFrom'], $data['until'], $data['features']);
    Measurement::query()->where('timestamp', '>=', strtotime('2026-10-08T21:55:00Z'))->delete();
    $this->travelTo(Date::parse('2026-10-16 12:00:00', 'UTC'));

    Livewire::withQueryParams(['date' => '2026-10-09'])->test(Comparison::class)
        ->assertDontSee('data-strip="improvement"', false);
});

it('draws the model under validation on its new days', function (): void {
    $data = heatingCalibrationData();
    TemperatureCalibration::factory()->for($data['sensor'])->create(['validated_until' => $data['from']]);
    $candidate = resolve(TemperatureCalibrator::class)->train($data['sensor']->id, $data['from'], $data['validateFrom'], $data['until'], $data['features']);
    $this->travelTo(Date::parse('2026-10-09 12:00:00', 'UTC'));

    $today = Livewire::test(Comparison::class);

    expect(array_filter(array_column($today->get('comparisonRows'), 3), fn (?float $value): bool => $value !== null))->not->toBeEmpty();
    $today->assertDontSee('Model in use')
        ->assertSee('Model under validation')->assertSee('Calibration #'.$candidate->id)
        ->assertSee('validating on new readings 8.10.2026 - 10.10.2026')
        ->assertSee('Improvement of model #'.$candidate->id.' · validating over the model in use');
});

it('says when there are no calibrations', function (): void {
    Sensor::factory()->create();

    Livewire::test(Comparison::class)->assertSee('No calibrations yet.')->assertDontSee('Model under validation');
});

it('lists every calibration with its scores and highlights the one in use whatever the date', function (): void {
    $this->travelTo(Date::parse('2026-10-20 12:00:00', LocalTime::TIMEZONE));
    $sensor = Sensor::factory()->create();
    $versus = fn (float $current, float $retrained): array => ['current' => ['all' => ['corrected_mae' => $current], 'sun_facing' => ['corrected_mae' => $current + 1.0]],
        'retrained' => ['all' => ['count' => 420, 'corrected_mae' => $retrained], 'sun_facing' => ['corrected_mae' => $retrained + 0.5]]];
    $first = TemperatureCalibration::factory()->for($sensor)->create(['validated_until' => strtotime('2026-10-14T22:00:00Z'),
        'scores' => ['validation' => ['all' => ['count' => 431, 'corrected_mae' => 0.9]]]]);
    $inUse = TemperatureCalibration::factory()->for($sensor)->create(['validated_until' => strtotime('2026-10-18T22:00:00Z'),
        'scores' => ['validation' => [], 'versus_current' => $versus(0.61, 0.48)]]);
    $rejected = TemperatureCalibration::factory()->for($sensor)->create(['accepted' => false, 'validated_until' => strtotime('2026-10-19T22:00:00Z'),
        'scores' => ['validation' => [], 'versus_current' => $versus(0.48, 0.55)]]);

    $page = Livewire::withQueryParams(['date' => '2026-10-01'])->test(Comparison::class);
    $rows = array_column($page->get('modelHistory'), null, 'id');

    expect(array_keys($rows))->toBe([$rejected->id, $inUse->id, $first->id])
        ->and($rows[$first->id])->toMatchArray(['observations' => 431, 'previous_mae' => null, 'own_mae' => 0.9, 'improvement' => null,
            'in_use_from' => $first->validated_until, 'in_use_until' => $inUse->validated_until, 'in_use_now' => false])
        ->and($rows[$inUse->id]['improvement'])->toEqualWithDelta(0.13, 0.0001)
        ->and($rows[$inUse->id]['sun_improvement'])->toEqualWithDelta(0.63, 0.0001)
        ->and($rows[$inUse->id])->toMatchArray(['in_use_from' => $inUse->validated_until, 'in_use_until' => null, 'in_use_now' => true])
        ->and($rows[$rejected->id])->toMatchArray(['status' => 'rejected', 'in_use_from' => null, 'in_use_now' => false])
        ->and($rows[$rejected->id]['improvement'])->toEqualWithDelta(-0.07, 0.0001);
    $page->assertSee('Model history')->assertSeeInOrder(['data-model-in-use', '#'.$inUse->id, 'in use', '420', '0,61', '0,48', '+0,13', '19.10.2026 00:00 - now'], false)
        ->assertDontSee('No model corrects the page now');
});

it('scores a model under validation on its days so far', function (): void {
    $data = heatingCalibrationData();
    TemperatureCalibration::factory()->for($data['sensor'])->create(['validated_until' => $data['from']]);
    $candidate = resolve(TemperatureCalibrator::class)->train($data['sensor']->id, $data['from'], $data['validateFrom'], $data['until'], $data['features']);
    $this->travelTo(Date::parse('2026-10-09 12:00:00', 'UTC'));

    $row = array_column(Livewire::test(Comparison::class)->get('modelHistory'), null, 'id')[$candidate->id];

    expect($row['status'])->toBe('validating')
        ->and($row['own_mae'])->not->toBeNull()
        ->and($row['observations'])->toBeGreaterThan(0)
        ->and($row['in_use_from'])->toBeNull();
});

it('scores a first model under validation on its own and says nothing corrects the page', function (): void {
    $data = heatingCalibrationData();
    $candidate = resolve(TemperatureCalibrator::class)->train($data['sensor']->id, $data['from'], $data['validateFrom'], $data['until'], $data['features']);
    $this->travelTo(Date::parse('2026-10-09 12:00:00', 'UTC'));

    $page = Livewire::test(Comparison::class);
    $row = array_column($page->get('modelHistory'), null, 'id')[$candidate->id];

    expect($row['previous_mae'])->toBeNull()
        ->and($row['own_mae'])->toBeLessThan(0.01)
        ->and($row['observations'])->toBeGreaterThan(144);
    $page->assertSee('No model corrects the page now');
});

it('ends today\'s axis on the next full hour and keeps past days whole', function (string $now, string $date, int $slots, string $last): void {
    $sensor = Sensor::factory()->create();
    $this->travelTo(Date::parse($now, LocalTime::TIMEZONE));

    $rows = Livewire::withQueryParams(['date' => $date])->test(Comparison::class)->get('comparisonRows');

    expect($rows)->toHaveCount($slots)
        ->and($rows[array_key_last($rows)][0])->toBe(LocalTime::of(Date::parse($last, LocalTime::TIMEZONE)->getTimestamp())->wallClockMs());
})->with([
    'after midnight' => ['2026-10-20 00:05:00', '2026-10-20', 7, '2026-10-20 01:00:00'],
    'at noon' => ['2026-10-20 12:00:00', '2026-10-20', 79, '2026-10-20 13:00:00'],
    'late evening' => ['2026-10-20 23:30:00', '2026-10-20', 144, '2026-10-20 23:50:00'],
    'yesterday' => ['2026-10-20 12:00:00', '2026-10-19', 144, '2026-10-19 23:50:00'],
]);

it('reuses the scores of a model under validation for ten minutes', function (): void {
    $data = heatingCalibrationData();
    TemperatureCalibration::factory()->for($data['sensor'])->create(['validated_until' => $data['from']]);
    $candidate = resolve(TemperatureCalibrator::class)->train($data['sensor']->id, $data['from'], $data['validateFrom'], $data['until'], $data['features']);
    $this->travelTo(Date::parse('2026-10-09 12:00:00', 'UTC'));
    $scores = fn (): ?float => array_column(Livewire::test(Comparison::class)->get('modelHistory'), null, 'id')[$candidate->id]['previous_mae'];
    $before = $scores();

    ReferenceTemperature::query()->delete();
    $this->travelTo(Date::parse('2026-10-09 12:09:00', 'UTC'));

    expect($before)->not->toBeNull()
        ->and($scores())->toBe($before);

    $this->travelTo(Date::parse('2026-10-09 12:11:00', 'UTC'));

    expect($scores())->toBeNull();
});

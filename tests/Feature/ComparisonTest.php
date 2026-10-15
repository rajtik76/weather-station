<?php

declare(strict_types=1);

use App\Livewire\Charts;
use App\Livewire\Comparison;
use App\Livewire\Overview;
use App\Models\Measurement;
use App\Models\ReferenceTemperature;
use App\Models\Sensor;
use App\Models\TemperatureCalibration;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

it('shows sensor, shade estimate and reference even when only reference data exists', function (): void {
    $this->travelTo(Date::parse('2026-10-16 12:00:00', 'UTC'));
    $slot = strtotime('2026-10-16T07:00:00Z');
    ReferenceTemperature::factory()->create(['timestamp' => $slot, 'temperature' => 10.0]);
    Http::preventStrayRequests();

    $page = Livewire::test(Comparison::class);
    $rows = $page->get('comparisonRows');

    expect($rows)->toHaveCount(144)
        ->and(array_values(array_filter($rows, fn (array $row): bool => $row[3] !== null))[0])
        ->toBe([1792141200000, null, null, 10.0]);
    $page->assertSee('Shield sensor')->assertSee('Shade estimate')->assertSee('ČHMÚ Mikulka')
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

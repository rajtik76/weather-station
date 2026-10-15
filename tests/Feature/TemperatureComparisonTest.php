<?php

declare(strict_types=1);

use App\Models\Measurement;
use App\Models\ReferenceTemperature;
use App\Models\Sensor;
use App\Models\TemperatureCalibration;
use App\Queries\CorrectedTemperature;
use App\Queries\TemperatureHistory;
use App\ValueObject\TemperatureComparison;
use Illuminate\Support\Facades\Date;

it('scores raw and corrected temperatures on the same nearby usable observations', function (): void {
    $this->travelTo(Date::parse('2026-10-16 08:00:00', 'UTC'));
    $sensor = Sensor::factory()->create();
    $end = strtotime('2026-10-16T07:00:00Z');
    TemperatureCalibration::factory()->for($sensor)->create();
    correctionHistory($sensor, $end);
    ReferenceTemperature::factory()->create(['timestamp' => $end, 'temperature' => 10.0]);
    ReferenceTemperature::factory()->create(['timestamp' => $end - 600, 'temperature' => null]);
    ReferenceTemperature::factory()->create(['station' => 'other', 'timestamp' => $end - 1200, 'temperature' => 40.0]);
    Measurement::factory()->v4()->for($sensor)->create(['timestamp' => $end + 180, 'data' => (string) litWindow(140000, 140000, 140000)]);
    $other = Sensor::factory()->create();
    correctionHistory($other, $end);

    $readings = resolve(TemperatureHistory::class)->readings($sensor->id, $end, $end + 600, withReference: true);
    $summary = TemperatureComparison::summary($readings);

    expect($summary['count'])->toBe(1)
        ->and($summary['measured']['mae'])->toBe(2.0)
        ->and($summary['estimated']['mae'])->toEqualWithDelta(1.1, 0.001)
        ->and($readings[1]['reference'])->toBeNull();
});

it('does not score missing estimates or replace them with the sensor reading', function (): void {
    $summary = TemperatureComparison::summary([
        ['timestamp' => 1, 'measured' => 12.0, 'estimated' => null, 'reference' => 10.0],
        ['timestamp' => 2, 'measured' => 12.0, 'estimated' => 11.0, 'reference' => null],
    ]);

    expect($summary)->toBe([
        'count' => 0, 'unavailable' => 1,
        'measured' => ['mae' => null, 'bias' => null, 'min' => null, 'max' => null],
        'estimated' => ['mae' => null, 'bias' => null, 'min' => null, 'max' => null],
    ]);
});

it('reports absolute mean error and signed extrema without cancellation', function (): void {
    $summary = TemperatureComparison::summary([
        ['timestamp' => 1, 'measured' => 14.0, 'estimated' => 12.0, 'reference' => 10.0],
        ['timestamp' => 2, 'measured' => 9.0, 'estimated' => 8.0, 'reference' => 10.0],
    ]);

    expect($summary['measured'])->toBe(['mae' => 2.5, 'bias' => 1.5, 'min' => -1.0, 'max' => 4.0])
        ->and($summary['estimated'])->toBe(['mae' => 2.0, 'bias' => 0.0, 'min' => -2.0, 'max' => 2.0]);
});

it('keeps batch estimates identical to individual corrections across calibration changes', function (): void {
    $sensor = Sensor::factory()->create();
    $end = strtotime('2026-10-16T07:00:00Z');
    TemperatureCalibration::factory()->for($sensor)->create();
    TemperatureCalibration::factory()->for($sensor)->create([
        'validated_until' => $end + 600,
        'coefficients' => [2.0, 0.0, 0.0, 0.1, 0.2, 0.3, 0.4, 0.5, 0.6],
    ]);
    correctionHistory($sensor, $end);
    Measurement::factory()->v4()->for($sensor)->create(['timestamp' => $end + 600, 'data' => (string) litWindow(140000, 140000, 140000)]);
    $correction = resolve(CorrectedTemperature::class);

    $batch = $correction->between($sensor->id, $end, $end + 1200);

    expect($batch[$end])->toBe($correction->at($sensor->id, $end))
        ->and($batch[$end + 600])->toBe($correction->at($sensor->id, $end + 600))
        ->and($batch[$end]['calibration_id'])->not->toBe($batch[$end + 600]['calibration_id']);
});

it('leaves a correction bucket empty when any of its measurements cannot be corrected', function (): void {
    $sensor = Sensor::factory()->create();
    $end = strtotime('2026-10-16T07:00:00Z');
    TemperatureCalibration::factory()->for($sensor)->create();
    correctionHistory($sensor, $end);
    Measurement::factory()->for($sensor)->create(['timestamp' => $end + 600]);

    $rows = resolve(TemperatureHistory::class)->correctedBuckets($sensor->id, $end, $end + 3600, 3600);

    expect($rows)->toHaveCount(1)
        ->and($rows[0][2])->toBeNull();
});

it('plots a reading stamped just before the mark in the slot of the matching reference', function (): void {
    $this->travelTo(Date::parse('2026-10-16 08:00:00', 'UTC'));
    $sensor = Sensor::factory()->create();
    $slot = strtotime('2026-10-16T07:00:00Z');
    Measurement::factory()->v4()->for($sensor)->create(['timestamp' => $slot - 30, 'data' => (string) litWindow(0, 0, 0)]);
    ReferenceTemperature::factory()->create(['timestamp' => $slot, 'temperature' => 10.0]);

    $rows = resolve(TemperatureHistory::class)->comparisonRows($sensor->id, $slot - 1200, $slot + 1200);
    $plotted = array_values(array_filter($rows, fn (array $row): bool => $row[1] !== null));

    expect($plotted)->toHaveCount(1)
        ->and($plotted[0][3])->toBe(10.0);
});

it('averages a correction bucket over all of its readings even when the window starts inside it', function (): void {
    $sensor = Sensor::factory()->create();
    $end = strtotime('2026-10-16T07:00:00Z');
    TemperatureCalibration::factory()->for($sensor)->create();
    correctionHistory($sensor, $end);
    $bucketStart = intdiv($end - 3600, 3600) * 3600;

    $whole = resolve(TemperatureHistory::class)->correctedBuckets($sensor->id, $bucketStart, $bucketStart + 7200, 3600);
    $partial = resolve(TemperatureHistory::class)->correctedBuckets($sensor->id, $end - 600, $bucketStart + 7200, 3600);

    expect($partial[0][2])->toBe($whole[0][2]);
});

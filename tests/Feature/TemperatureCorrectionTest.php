<?php

declare(strict_types=1);

use App\Enums\ProtocolVersion;
use App\Models\Measurement;
use App\Models\ReferenceTemperature;
use App\Models\Sensor;
use App\Models\StationEvent;
use App\Models\TemperatureCalibration;
use App\Queries\CorrectedTemperature;
use App\Queries\TemperatureDataset;
use App\ValueObject\MeasurementDataV4;
use App\ValueObject\ShieldFeatures;
use App\ValueObject\ShieldSample;
use App\ValueObject\SolarPosition;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\assertDatabaseHas;

/** @return list<Measurement> */
function correctionHistory(Sensor $sensor, int $end, ?int $missingAt = null): array
{
    $rows = [];

    for ($index = 0; $index <= 6; $index++) {
        $rows[] = Measurement::factory()->for($sensor)->create([
            'timestamp' => $end - (6 - $index) * 600,
            'protocol_version' => ProtocolVersion::V4,
            'data' => (string) ($index === $missingAt
                ? new MeasurementDataV4(temperature: 1200, humidity: 6000, pressure: 97000,
                    temperatureMin: 1200, temperatureMax: 1200, humidityMin: 6000, humidityMax: 6000,
                    pressureMin: 97000, pressureMax: 97000, samples: 20)
                : litWindow(140000, 140000, 140000)),
        ]);
    }

    return $rows;
}

it('computes a shade estimate from one sensor without contacting a reference or changing its records', function (): void {
    Http::preventStrayRequests();
    $sensor = Sensor::factory()->create();
    $end = strtotime('2026-10-16T07:00:00Z');
    $calibration = TemperatureCalibration::factory()->for($sensor)->create();
    $readings = correctionHistory($sensor, $end);
    $other = Sensor::factory()->create();
    correctionHistory($other, $end);
    TemperatureCalibration::factory()->for($other)->create(['coefficients' => array_fill(0, 9, 10.0)]);
    $original = $readings[6]->getRawOriginal('data');

    $result = new CorrectedTemperature(new TemperatureDataset)->at($sensor->id, $end);

    expect($result['estimated'] ?? null)->toEqualWithDelta(11.1, 0.001)
        ->and($result['measured'] ?? null)->toBe(12.0)
        ->and($result['calibration_id'] ?? null)->toBe($calibration->id);
    assertDatabaseHas('measurements', ['id' => $readings[6]->id, 'data' => $original]);
    Http::assertNothingSent();
});

it('does not use a calibration before its validation period ended', function (): void {
    $sensor = Sensor::factory()->create();
    $end = strtotime('2026-10-14T07:00:00Z');
    TemperatureCalibration::factory()->for($sensor)->create(['validated_until' => $end + 1]);
    correctionHistory($sensor, $end);

    expect(new CorrectedTemperature(new TemperatureDataset)->at($sensor->id, $end))->toBeNull();
});

it('ignores rejected or incompatible calibrations', function (bool $accepted, int $version): void {
    $sensor = Sensor::factory()->create();
    $end = strtotime('2026-10-16T07:00:00Z');
    TemperatureCalibration::factory()->for($sensor)->create(['accepted' => $accepted, 'feature_version' => $version]);
    correctionHistory($sensor, $end);

    expect(new CorrectedTemperature(new TemperatureDataset)->at($sensor->id, $end))->toBeNull();
})->with([
    'rejected' => [false, ShieldFeatures::VERSION],
    'incompatible' => [true, ShieldFeatures::VERSION + 1],
]);

it('does not reuse an earlier estimate when the newest window lacks light', function (): void {
    $sensor = Sensor::factory()->create();
    $end = strtotime('2026-10-16T07:00:00Z');
    TemperatureCalibration::factory()->for($sensor)->create();
    correctionHistory($sensor, $end, missingAt: 6);

    expect(new CorrectedTemperature(new TemperatureDataset)->at($sensor->id, $end))->toBeNull();
});

it('pairs a window only with a nearby real Mikulka observation', function (): void {
    $slot = strtotime('2026-10-01T07:00:00Z');
    ReferenceTemperature::factory()->create(['timestamp' => $slot, 'temperature' => 10.0]);
    ReferenceTemperature::factory()->create(['timestamp' => $slot + 600, 'temperature' => null]);
    ReferenceTemperature::factory()->create(['station' => 'another-station', 'timestamp' => $slot + 1200, 'temperature' => 99.0]);
    $sun = new SolarPosition(20.0, 110.0);
    $sample = fn (int $time): ShieldSample => new ShieldSample($time, 12.0, 1400.0, $sun, true, array_fill(0, 9, 0.0));

    $pairs = (new TemperatureDataset)->pair([
        $sample($slot - 30),
        $sample($slot + 180),
        $sample($slot + 570),
        $sample($slot + 1170),
    ]);

    expect($pairs)->toHaveCount(1)
        ->and($pairs[0]['reference'])->toBe(10.0)
        ->and($pairs[0]['sample']->timestamp)->toBe($slot - 30);
});

it('stops applying a calibration once a station event follows its training start', function (int $offset, bool $applies): void {
    $sensor = Sensor::factory()->create();
    $end = strtotime('2026-10-16T07:00:00Z');
    $calibration = TemperatureCalibration::factory()->for($sensor)->create();
    StationEvent::factory()->create(['sensor_id' => $sensor->id, 'occurred_at' => Date::createFromTimestampUTC($calibration->trained_from + $offset)]);
    correctionHistory($sensor, $end);

    $result = new CorrectedTemperature(new TemperatureDataset)->at($sensor->id, $end);

    expect($result !== null)->toBe($applies);
})->with([
    'sensor swapped after training began' => [3600, false],
    'swap before training began' => [-3600, true],
]);

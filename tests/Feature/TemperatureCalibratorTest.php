<?php

declare(strict_types=1);

use App\Enums\ProtocolVersion;
use App\Models\Measurement;
use App\Models\ReferenceTemperature;
use App\Models\Sensor;
use App\Models\StationEvent;
use App\Models\TemperatureCalibration;
use App\Queries\TemperatureCalibrator;
use App\Queries\TemperatureDataset;
use App\ValueObject\ChmuTemperatures;
use App\ValueObject\LightWindow;
use App\ValueObject\LocalTime;
use App\ValueObject\MeasurementDataV4;
use App\ValueObject\ShieldFeatures;
use App\ValueObject\SolarPosition;
use App\ValueObject\StationSite;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;

use function Pest\Laravel\assertDatabaseCount;

/** @return array{sensor: Sensor, from: int, validateFrom: int, until: int, features: ShieldFeatures} */
function heatingCalibrationData(): array
{
    $sensor = Sensor::factory()->create();
    $from = Date::parse('2026-10-01', LocalTime::TIMEZONE)->getTimestamp();
    $validateFrom = Date::parse('2026-10-08', LocalTime::TIMEZONE)->getTimestamp();
    $until = Date::parse('2026-10-11', LocalTime::TIMEZONE)->getTimestamp();
    $features = new ShieldFeatures(StationSite::LATITUDE, StationSite::LONGITUDE, StationSite::WALL_AZIMUTH);
    $measurements = $references = [];
    $createdAt = now()->toDateTimeString();

    for ($timestamp = $from + 570; $timestamp < $until; $timestamp += 600) {
        $sun = SolarPosition::at($timestamp - 300, StationSite::LATITUDE, StationSite::LONGITUDE);
        $lit = $sun->faces(StationSite::WALL_AZIMUTH);
        $temperature = $lit ? 1300 : 1000;
        $lux = $lit ? 200000 : 0;
        $data = new MeasurementDataV4(
            temperature: $temperature, humidity: 6000, pressure: 97000,
            temperatureMin: $temperature, temperatureMax: $temperature,
            humidityMin: 6000, humidityMax: 6000,
            pressureMin: 97000, pressureMax: 97000,
            samples: 20,
            light: new LightWindow($lux, $lux, $lux),
        );
        $measurements[] = [
            'sensor_id' => $sensor->id,
            'timestamp' => $timestamp,
            'protocol_version' => ProtocolVersion::V4->value,
            'data' => (string) $data,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
        $references[] = [
            'station' => ChmuTemperatures::STATION,
            'timestamp' => (int) round($timestamp / 600) * 600,
            'temperature' => 10.0,
            'quality' => 0,
            'source' => 'calibration.json',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
    }

    foreach (array_chunk($measurements, 300) as $chunk) {
        Measurement::query()->insert($chunk);
    }

    foreach (array_chunk($references, 300) as $chunk) {
        ReferenceTemperature::query()->insert($chunk);
    }

    return ['sensor' => $sensor, 'from' => $from, 'validateFrom' => $validateFrom, 'until' => $until, 'features' => $features];
}

it('learns a heating correction and validates on later complete days', function (): void {
    $data = heatingCalibrationData();
    $this->travelTo(Date::parse('2026-10-16'));

    $exitCode = Artisan::call('temperature:calibrate', [
        'sensor' => $data['sensor']->slug,
        '--from' => '2026-10-01', '--validate-from' => '2026-10-08', '--until' => '2026-10-11',
    ]);

    $calibration = TemperatureCalibration::query()->sole();

    expect($exitCode)->toBe(0);
    expect($calibration->accepted)->toBeTrue()
        ->and($calibration->scores['validation']['all']['corrected_mae'])->toBeLessThan(0.01)
        ->and($calibration->scores['validation']['night']['corrected_mae'])->toBeLessThan(0.01)
        ->and($calibration->scores['validation']['all']['count'])->toBe(431)
        ->and($calibration->trained_until)->toBe($data['validateFrom']);
});

it('does not fit validation references and retains a rejected candidate separately', function (): void {
    $data = heatingCalibrationData();
    $calibrator = new TemperatureCalibrator(new TemperatureDataset);
    $original = $calibrator->calibrate($data['sensor']->id, $data['from'], $data['validateFrom'], $data['until'], $data['features']);
    ReferenceTemperature::query()->where('timestamp', '>=', $data['validateFrom'])->update(['temperature' => 30.0]);

    $candidate = $calibrator->calibrate($data['sensor']->id, $data['from'], $data['validateFrom'], $data['until'], $data['features']);

    expect($candidate->coefficients)->toBe($original->coefficients)
        ->and($candidate->accepted)->toBeFalse()
        ->and($original->refresh()->accepted)->toBeTrue();
    assertDatabaseCount('temperature_calibrations', 2);
});

it('refuses calibration without enough independent reference days', function (): void {
    $sensor = Sensor::factory()->create();

    expect(fn (): TemperatureCalibration => new TemperatureCalibrator(new TemperatureDataset)->calibrate(
        $sensor->id, 1000, 2000, 3000,
        new ShieldFeatures(StationSite::LATITUDE, StationSite::LONGITUDE, StationSite::WALL_AZIMUTH),
    ))->toThrow(InvalidArgumentException::class, 'At least seven calibration days');

    assertDatabaseCount('temperature_calibrations', 0);
});

it('does not allow overlapping or reversed calibration periods', function (): void {
    expect(fn (): TemperatureCalibration => new TemperatureCalibrator(new TemperatureDataset)->calibrate(
        1, 2000, 1000, 3000, new ShieldFeatures(49.7, 13.4, 65.0),
    ))->toThrow(InvalidArgumentException::class, 'from < validate-from < until');
});

it('refuses a calibration range that contains a station event', function (): void {
    $data = heatingCalibrationData();
    StationEvent::factory()->create(['sensor_id' => $data['sensor']->id, 'occurred_at' => Date::createFromTimestampUTC($data['from'] + 86400)]);

    expect(fn (): TemperatureCalibration => new TemperatureCalibrator(new TemperatureDataset)->calibrate(
        $data['sensor']->id, $data['from'], $data['validateFrom'], $data['until'], $data['features'],
    ))->toThrow(InvalidArgumentException::class, 'contains a station event');

    assertDatabaseCount('temperature_calibrations', 0);
});

it('ignores station events outside the range or on other sensors', function (): void {
    $data = heatingCalibrationData();
    StationEvent::factory()->create(['sensor_id' => $data['sensor']->id, 'occurred_at' => Date::createFromTimestampUTC($data['from'] - 3600)]);
    StationEvent::factory()->create(['occurred_at' => Date::createFromTimestampUTC($data['from'] + 86400)]);

    $calibration = new TemperatureCalibrator(new TemperatureDataset)->calibrate(
        $data['sensor']->id, $data['from'], $data['validateFrom'], $data['until'], $data['features'],
    );

    expect($calibration->accepted)->toBeTrue();
});

it('rejects a correction that barely improves the sun-facing error', function (): void {
    $data = heatingCalibrationData();
    $calibrator = new TemperatureCalibrator(new TemperatureDataset);
    ReferenceTemperature::query()->where('timestamp', '>=', $data['validateFrom'])->get()->each(function (ReferenceTemperature $reference): void {
        $lit = SolarPosition::at($reference->timestamp - 330, StationSite::LATITUDE, StationSite::LONGITUDE)->faces(StationSite::WALL_AZIMUTH);
        $reference->update(['temperature' => $lit ? 11.45 : 10.0]);
    });

    $calibration = $calibrator->calibrate($data['sensor']->id, $data['from'], $data['validateFrom'], $data['until'], $data['features']);
    $sun = $calibration->scores['validation']['sun_facing'];

    expect($sun['corrected_mae'] < $sun['raw_mae'])->toBeTrue()
        ->and($calibration->accepted)->toBeFalse();
});

it('accepts the default until date while it is already tomorrow in UTC terms', function (): void {
    $sensor = Sensor::factory()->create();
    $this->travelTo(Date::parse('2026-10-15 22:30:00', 'UTC'));

    $exitCode = Artisan::call('temperature:calibrate', [
        'sensor' => $sensor->slug, '--from' => '2026-10-01', '--validate-from' => '2026-10-08',
    ]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('At least seven calibration days');
});

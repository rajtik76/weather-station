<?php

declare(strict_types=1);

use App\Models\ReferenceTemperature;
use App\Models\Sensor;
use App\Models\StationEvent;
use App\Models\TemperatureCalibration;
use App\Queries\TemperatureCalibrator;
use App\ValueObject\LocalTime;
use App\ValueObject\ShieldFeatures;
use App\ValueObject\SolarPosition;
use App\ValueObject\StationSite;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;

use function Pest\Laravel\assertDatabaseCount;

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
    $calibrator = resolve(TemperatureCalibrator::class);
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

    expect(fn (): TemperatureCalibration => resolve(TemperatureCalibrator::class)->calibrate(
        $sensor->id, 1000, 2000, 3000,
        new ShieldFeatures(StationSite::LATITUDE, StationSite::LONGITUDE, StationSite::WALL_AZIMUTH),
    ))->toThrow(InvalidArgumentException::class, 'At least seven calibration days');

    assertDatabaseCount('temperature_calibrations', 0);
});

it('does not allow overlapping or reversed calibration periods', function (): void {
    expect(fn (): TemperatureCalibration => resolve(TemperatureCalibrator::class)->calibrate(
        1, 2000, 1000, 3000, new ShieldFeatures(49.7, 13.4, 65.0),
    ))->toThrow(InvalidArgumentException::class, 'from < validate-from < until');
});

it('calibrates a range that contains a station event', function (): void {
    $data = heatingCalibrationData();
    StationEvent::factory()->create(['sensor_id' => $data['sensor']->id, 'occurred_at' => Date::createFromTimestampUTC($data['from'] + 86400)]);

    $calibration = resolve(TemperatureCalibrator::class)->calibrate(
        $data['sensor']->id, $data['from'], $data['validateFrom'], $data['until'], $data['features'],
    );

    expect($calibration->accepted)->toBeTrue();
    assertDatabaseCount('temperature_calibrations', 1);
});

it('ignores station events outside the range or on other sensors', function (): void {
    $data = heatingCalibrationData();
    StationEvent::factory()->create(['sensor_id' => $data['sensor']->id, 'occurred_at' => Date::createFromTimestampUTC($data['from'] - 3600)]);
    StationEvent::factory()->create(['occurred_at' => Date::createFromTimestampUTC($data['from'] + 86400)]);

    $calibration = resolve(TemperatureCalibrator::class)->calibrate(
        $data['sensor']->id, $data['from'], $data['validateFrom'], $data['until'], $data['features'],
    );

    expect($calibration->accepted)->toBeTrue();
});

it('rejects a correction that barely improves the sun-facing error', function (): void {
    $data = heatingCalibrationData();
    $calibrator = resolve(TemperatureCalibrator::class);
    ReferenceTemperature::query()->where('timestamp', '>=', $data['validateFrom'])->get()->each(function (ReferenceTemperature $reference): void {
        $lit = SolarPosition::at($reference->timestamp - 330, StationSite::LATITUDE, StationSite::LONGITUDE)->faces(StationSite::WALL_AZIMUTH);
        $reference->update(['temperature' => $lit ? 11.45 : 10.0]);
    });

    $calibration = $calibrator->calibrate($data['sensor']->id, $data['from'], $data['validateFrom'], $data['until'], $data['features']);
    $sun = $calibration->scores['validation']['sun_facing'];

    expect($sun['corrected_mae'] < $sun['raw_mae'])->toBeTrue()
        ->and($calibration->accepted)->toBeFalse();
});

it('rejects a retrained model that beats the raw sensor but not the model in use', function (): void {
    $data = heatingCalibrationData();
    $calibrator = resolve(TemperatureCalibrator::class);
    $inUse = $calibrator->calibrate($data['sensor']->id, $data['from'], $data['validateFrom'], $data['until'], $data['features'])->replicate();
    $inUse->fill(['trained_from' => $data['from'] - 20 * 86400, 'trained_until' => $data['from'] - 10 * 86400, 'validated_until' => $data['validateFrom']])->save();
    ReferenceTemperature::query()->where('timestamp', '<', $data['validateFrom'])->get()->each(function (ReferenceTemperature $reference): void {
        $lit = SolarPosition::at($reference->timestamp - 330, StationSite::LATITUDE, StationSite::LONGITUDE)->faces(StationSite::WALL_AZIMUTH);
        $reference->update(['temperature' => $lit ? 11.0 : 10.0]);
    });

    $retrained = $calibrator->calibrate($data['sensor']->id, $data['from'], $data['validateFrom'], $data['until'], $data['features']);
    $sun = $retrained->scores['validation']['sun_facing'];
    $current = $retrained->scores['versus_current']['current']['sun_facing']['corrected_mae'] ?? null;
    $replacement = $retrained->scores['versus_current']['retrained']['sun_facing']['corrected_mae'] ?? 0.0;

    expect($sun['corrected_mae'])->toBeLessThan($sun['raw_mae'] * 0.9)
        ->and($current)->not->toBeNull()
        ->and($current)->toBeLessThan($replacement)
        ->and($retrained->accepted)->toBeFalse();
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

it('retrains on a rolling window ending at local midnight with the previous geometry', function (): void {
    $data = heatingCalibrationData();
    $geometry = ['latitude' => StationSite::LATITUDE, 'longitude' => StationSite::LONGITUDE, 'wall_azimuth' => StationSite::WALL_AZIMUTH];
    $previous = TemperatureCalibration::factory()->for($data['sensor'])->create([
        'trained_from' => $data['from'] - 20 * 86400, 'trained_until' => $data['from'] - 10 * 86400, 'validated_until' => $data['from'] - 7 * 86400,
        'geometry' => $geometry,
    ]);
    Sensor::factory()->create();
    $this->travelTo(Date::parse('2026-10-11 00:15:00', LocalTime::TIMEZONE));

    $exitCode = Artisan::call('temperature:retrain');
    $retrained = TemperatureCalibration::query()->whereKeyNot($previous->id)->sole();

    expect($exitCode)->toBe(0)
        ->and($retrained->sensor_id)->toBe($data['sensor']->id)
        ->and($retrained->trained_from)->toBe(Date::parse('2026-09-24', LocalTime::TIMEZONE)->getTimestamp())
        ->and($retrained->trained_until)->toBe($data['validateFrom'])
        ->and($retrained->validated_until)->toBe($data['until'])
        ->and($retrained->geometry)->toEqual($geometry)
        ->and($retrained->accepted)->toBeTrue();

    expect(Artisan::call('temperature:retrain'))->toBe(0);
    assertDatabaseCount('temperature_calibrations', 2);
});

it('warns without failing when the rolling window lacks enough data', function (): void {
    $sensor = Sensor::factory()->create();
    TemperatureCalibration::factory()->for($sensor)->create(['validated_until' => strtotime('2026-10-01T00:00:00Z')]);
    $this->travelTo(Date::parse('2026-10-16 00:15:00', LocalTime::TIMEZONE));

    expect(Artisan::call('temperature:retrain'))->toBe(0)
        ->and(Artisan::output())->toContain('At least seven calibration days');
    assertDatabaseCount('temperature_calibrations', 1);
});

<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\TemperatureCalibration;
use App\ValueObject\LocalTime;
use App\ValueObject\NonNegativeRegression;
use App\ValueObject\ShieldFeatures;
use App\ValueObject\ShieldModel;
use App\ValueObject\ShieldSample;
use App\ValueObject\TemperatureScore;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;

/**
 * @phpstan-import-type Score from TemperatureScore
 * @phpstan-import-type ScoredPair from TemperatureScore
 *
 * @phpstan-type Pair array{sample: ShieldSample, reference: float, reference_at: int}
 */
final readonly class TemperatureCalibrator
{
    public const int VALIDATION_DAYS = 3;

    private const float REQUIRED_MAE_RATIO = 0.9;

    public function __construct(private TemperatureDataset $dataset, private CorrectedTemperature $corrections) {}

    public function calibrate(int $sensorId, int $from, int $validateFrom, int $until, ShieldFeatures $features): TemperatureCalibration
    {
        if ($from >= $validateFrom || $validateFrom >= $until) {
            throw new InvalidArgumentException('Calibration requires from < validate-from < until.');
        }

        $training = $this->pairs($sensorId, $from, $from, $validateFrom, $features);
        $validation = $this->pairs($sensorId, $from, $validateFrom, $until, $features);

        if ($this->completeDays($training) < 7 || $this->completeDays($validation) < self::VALIDATION_DAYS) {
            throw new InvalidArgumentException('At least seven calibration days and '.self::VALIDATION_DAYS.' later validation days with 100 reference pairs each are required.');
        }

        return $this->scoreValidation($this->fit($sensorId, $from, $validateFrom, $until, $features, $training), $validation);
    }

    /** Training [from, until), validation [until, validatedUntil); stored unaccepted. */
    public function train(int $sensorId, int $from, int $until, int $validatedUntil, ShieldFeatures $features): TemperatureCalibration
    {
        if ($from >= $until || $until >= $validatedUntil) {
            throw new InvalidArgumentException('Training requires from < until < validated-until.');
        }

        $training = $this->pairs($sensorId, $from, $from, $until, $features);

        if ($this->completeDays($training) < 7) {
            throw new InvalidArgumentException('At least seven calibration days with 100 reference pairs each are required.');
        }

        return $this->fit($sensorId, $from, $until, $validatedUntil, $features, $training);
    }

    public function validate(TemperatureCalibration $calibration): TemperatureCalibration
    {
        return $this->scoreValidation($calibration, $this->pairs($calibration->sensor_id, $calibration->trained_from, $calibration->trained_until, $calibration->validated_until, $calibration->features()));
    }

    /** @param list<Pair> $pairs */
    private function scoreValidation(TemperatureCalibration $calibration, array $pairs): TemperatureCalibration
    {
        $validation = $this->withHeating($pairs, $calibration->model());
        $versusCurrent = $this->versusCurrent($calibration->sensor_id, $calibration->trained_until, $calibration->validated_until, $validation);
        $scores = [
            ...$calibration->scores,
            'validation' => $this->scores($validation),
            'days' => $this->byDay($validation),
            'versus_current' => $versusCurrent,
        ];
        $all = $scores['validation']['all'];
        $sun = $scores['validation']['sun_facing'];
        $night = $scores['validation']['night'];
        $shade = $scores['validation']['shade'];
        $accepted = $this->completeDays($pairs) >= self::VALIDATION_DAYS
            && $all['count'] >= 0.9 * count($validation)
            && $this->improves($all)
            && $this->improves($sun) && abs($sun['corrected_bias'] ?? INF) < abs($sun['raw_bias'] ?? 0.0)
            && $night['count'] > 0 && $night['corrected_mae'] <= $night['raw_mae'] + 0.05
            && $shade['count'] > 0 && $shade['corrected_mae'] <= $shade['raw_mae'] + 0.1
            && $this->beatsCurrent($versusCurrent);
        $calibration->update(['accepted' => $accepted, 'scores' => $scores]);

        return $calibration;
    }

    /** @param list<Pair> $training */
    private function fit(int $sensorId, int $from, int $until, int $validatedUntil, ShieldFeatures $features, array $training): TemperatureCalibration
    {
        $sunnyDays = [];

        foreach ($training as $pair) {
            if ($pair['sample']->facesSun && $pair['sample']->illuminance > 1800) {
                $sunnyDays[Date::createFromTimestamp($pair['sample']->timestamp, LocalTime::TIMEZONE)->format('Y-m-d')] = true;
            }
        }

        if (count($sunnyDays) < 2) {
            throw new InvalidArgumentException('Calibration requires at least two mornings above 1800 lx on the sun-facing shield.');
        }

        $coefficients = NonNegativeRegression::fit(
            array_column(array_column($training, 'sample'), 'features'),
            array_map(fn (array $pair): float => $pair['sample']->measured - $pair['reference'], $training),
        );
        $sunFacing = array_values(array_filter($training, fn (array $pair): bool => $pair['sample']->facesSun));
        $limits = [
            'max_lux' => max(0.0, ...array_map(fn (array $pair): float => $pair['sample']->illuminance, $sunFacing)),
            'max_heating' => max(0.0, ...array_map(fn (array $pair): float => $pair['sample']->measured - $pair['reference'], $sunFacing)),
        ];
        $model = new ShieldModel($coefficients, $limits['max_lux'], $limits['max_heating']);

        return TemperatureCalibration::query()->create([
            'sensor_id' => $sensorId,
            'feature_version' => ShieldFeatures::VERSION,
            'trained_from' => $from,
            'trained_until' => $until,
            'validated_until' => $validatedUntil,
            'accepted' => false,
            'geometry' => ['latitude' => $features->latitude, 'longitude' => $features->longitude, 'wall_azimuth' => $features->wallAzimuth],
            'coefficients' => $coefficients,
            'limits' => $limits,
            'scores' => ['training' => $this->scores($this->withHeating($training, $model))],
        ]);
    }

    /**
     * Sample and reference in [from, until); feature history from max(historyFrom, from - HISTORY_SECONDS).
     *
     * @return list<Pair>
     */
    private function pairs(int $sensorId, int $historyFrom, int $from, int $until, ShieldFeatures $features): array
    {
        $samples = $this->dataset->samples($sensorId, max($historyFrom, $from - ShieldFeatures::HISTORY_SECONDS), $until, $features);

        return array_values(array_filter($this->dataset->pair($samples), fn (array $pair): bool => $pair['sample']->timestamp >= $from
            && $pair['reference_at'] >= $from && $pair['reference_at'] < $until));
    }

    /** @param list<Pair> $pairs */
    private function completeDays(array $pairs): int
    {
        $counts = [];

        foreach ($pairs as $pair) {
            $day = Date::createFromTimestamp($pair['sample']->timestamp, LocalTime::TIMEZONE)->format('Y-m-d');
            $counts[$day] = ($counts[$day] ?? 0) + 1;
        }

        return count(array_filter($counts, fn (int $count): bool => $count >= 100));
    }

    /**
     * @param  list<Pair>  $pairs
     * @return list<ScoredPair>
     */
    private function withHeating(array $pairs, ShieldModel $model): array
    {
        return array_map(fn (array $pair): array => [...$pair, 'heating' => $model->heating($pair['sample'])], $pairs);
    }

    /**
     * Both models scored on the validation pairs where each has an estimate; empty without a model in use.
     *
     * @param  list<ScoredPair>  $validation
     * @return array{current?: array<string, Score>, retrained?: array<string, Score>}
     */
    private function versusCurrent(int $sensorId, int $validateFrom, int $until, array $validation): array
    {
        $current = $this->corrections->between($sensorId, $validateFrom, $until);
        $common = array_values(array_filter($validation, fn (array $pair): bool => $pair['heating'] !== null
            && ($current[$pair['sample']->timestamp]['correction'] ?? null) !== null));

        return $common === [] ? [] : [
            'current' => $this->scores(array_map(fn (array $pair): array => [...$pair, 'heating' => $current[$pair['sample']->timestamp]['correction']], $common)),
            'retrained' => $this->scores($common),
        ];
    }

    /** @param array{current?: array<string, Score>, retrained?: array<string, Score>} $versusCurrent */
    private function beatsCurrent(array $versusCurrent): bool
    {
        if (! isset($versusCurrent['current'], $versusCurrent['retrained'])) {
            return true;
        }

        foreach (['all', 'sun_facing'] as $group) {
            $current = $versusCurrent['current'][$group]['corrected_mae'];
            $retrained = $versusCurrent['retrained'][$group]['corrected_mae'];

            if ($current !== null && ($retrained === null || $retrained > $current)) {
                return false;
            }
        }

        return true;
    }

    /** @param Score $score */
    private function improves(array $score): bool
    {
        return $score['raw_mae'] !== null && $score['corrected_mae'] !== null
            && $score['corrected_mae'] <= $score['raw_mae'] * self::REQUIRED_MAE_RATIO;
    }

    /**
     * @param  list<ScoredPair>  $pairs
     * @return array<string, Score>
     */
    private function scores(array $pairs): array
    {
        return [
            'all' => TemperatureScore::of($pairs),
            'sun_facing' => TemperatureScore::of(array_values(array_filter($pairs, fn (array $pair): bool => $pair['sample']->facesSun))),
            'shade' => TemperatureScore::of(array_values(array_filter($pairs, fn (array $pair): bool => $pair['sample']->sun->elevation > 0 && ! $pair['sample']->facesSun))),
            'night' => TemperatureScore::of(array_values(array_filter($pairs, fn (array $pair): bool => $pair['sample']->sun->elevation <= 0))),
        ];
    }

    /**
     * @param  list<ScoredPair>  $pairs
     * @return array<string, Score>
     */
    private function byDay(array $pairs): array
    {
        $days = [];

        foreach ($pairs as $pair) {
            $days[Date::createFromTimestamp($pair['sample']->timestamp, LocalTime::TIMEZONE)->format('Y-m-d')][] = $pair;
        }

        return array_map(TemperatureScore::of(...), $days);
    }
}

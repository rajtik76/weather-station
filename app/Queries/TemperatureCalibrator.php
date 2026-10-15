<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\StationEvent;
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
    private const float REQUIRED_MAE_RATIO = 0.9;

    public function __construct(private TemperatureDataset $dataset) {}

    public function calibrate(int $sensorId, int $from, int $validateFrom, int $until, ShieldFeatures $features): TemperatureCalibration
    {
        if ($from >= $validateFrom || $validateFrom >= $until) {
            throw new InvalidArgumentException('Calibration requires from < validate-from < until.');
        }

        if (StationEvent::query()->between($sensorId, $from, $until)->exists()) {
            throw new InvalidArgumentException('Calibration range contains a station event; start after the latest hardware change.');
        }

        $pairs = $this->dataset->pair($this->dataset->samples($sensorId, $from, $until, $features));
        $training = array_values(array_filter($pairs, fn (array $pair): bool => $pair['sample']->timestamp < $validateFrom && $pair['reference_at'] < $validateFrom));
        $validation = array_values(array_filter($pairs, fn (array $pair): bool => $pair['sample']->timestamp >= $validateFrom && $pair['reference_at'] >= $validateFrom && $pair['reference_at'] < $until));

        if ($this->completeDays($training) < 7 || $this->completeDays($validation) < 3) {
            throw new InvalidArgumentException('At least seven calibration days and three later validation days with 100 reference pairs each are required.');
        }

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
        $scoredTraining = $this->withHeating($training, $model);
        $scoredValidation = $this->withHeating($validation, $model);
        $scores = [
            'training' => $this->scores($scoredTraining),
            'validation' => $this->scores($scoredValidation),
            'days' => $this->byDay($scoredValidation),
        ];
        $all = $scores['validation']['all'];
        $sun = $scores['validation']['sun_facing'];
        $night = $scores['validation']['night'];
        $shade = $scores['validation']['shade'];
        $accepted = $all['count'] >= 0.9 * count($validation)
            && $this->improves($all)
            && $this->improves($sun) && abs($sun['corrected_bias'] ?? INF) < abs($sun['raw_bias'] ?? 0.0)
            && $night['count'] > 0 && $night['corrected_mae'] <= $night['raw_mae'] + 0.05
            && $shade['count'] > 0 && $shade['corrected_mae'] <= $shade['raw_mae'] + 0.1;

        return TemperatureCalibration::query()->create([
            'sensor_id' => $sensorId,
            'feature_version' => ShieldFeatures::VERSION,
            'trained_from' => $from,
            'trained_until' => $validateFrom,
            'validated_until' => $until,
            'accepted' => $accepted,
            'geometry' => ['latitude' => $features->latitude, 'longitude' => $features->longitude, 'wall_azimuth' => $features->wallAzimuth],
            'coefficients' => $coefficients,
            'limits' => $limits,
            'scores' => $scores,
        ]);
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

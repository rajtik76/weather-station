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

/** @phpstan-type Pair array{sample: ShieldSample, reference: float, reference_at: int} */
final readonly class TemperatureCalibrator
{
    public function __construct(private TemperatureDataset $dataset) {}

    public function calibrate(int $sensorId, int $from, int $validateFrom, int $until, ShieldFeatures $features): TemperatureCalibration
    {
        if ($from >= $validateFrom || $validateFrom >= $until) {
            throw new InvalidArgumentException('Calibration requires from < validate-from < until.');
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
        $limits = [
            'max_lux' => max(0.0, ...array_map(fn (array $pair): float => $pair['sample']->illuminance, $training)),
            'max_heating' => max(0.0, ...array_map(fn (array $pair): float => $pair['sample']->measured - $pair['reference'], $training)),
        ];
        $model = new ShieldModel($coefficients, $limits['max_lux'], $limits['max_heating']);
        $scores = [
            'training' => $this->scores($training, $model),
            'validation' => $this->scores($validation, $model),
            'days' => $this->byDay($validation, $model),
        ];
        $all = $scores['validation']['all'];
        $sun = $scores['validation']['sun_facing'];
        $night = $scores['validation']['night'];
        $shade = $scores['validation']['shade'];
        $accepted = $all['count'] >= 0.9 * count($validation)
            && $all['corrected_mae'] < $all['raw_mae']
            && $sun['count'] > 0 && $sun['corrected_mae'] < $sun['raw_mae']
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
     * @return array<string, array{count: int, unavailable: int, raw_mae: ?float, corrected_mae: ?float, raw_bias: ?float, corrected_bias: ?float}>
     */
    private function scores(array $pairs, ShieldModel $model): array
    {
        return [
            'all' => TemperatureScore::of($pairs, $model),
            'sun_facing' => TemperatureScore::of(array_values(array_filter($pairs, fn (array $pair): bool => $pair['sample']->facesSun)), $model),
            'shade' => TemperatureScore::of(array_values(array_filter($pairs, fn (array $pair): bool => $pair['sample']->sun->elevation > 0 && ! $pair['sample']->facesSun)), $model),
            'night' => TemperatureScore::of(array_values(array_filter($pairs, fn (array $pair): bool => $pair['sample']->sun->elevation <= 0)), $model),
        ];
    }

    /**
     * @param  list<Pair>  $pairs
     * @return array<string, array{count: int, unavailable: int, raw_mae: ?float, corrected_mae: ?float, raw_bias: ?float, corrected_bias: ?float}>
     */
    private function byDay(array $pairs, ShieldModel $model): array
    {
        $days = [];

        foreach ($pairs as $pair) {
            $days[Date::createFromTimestamp($pair['sample']->timestamp, LocalTime::TIMEZONE)->format('Y-m-d')][] = $pair;
        }

        return array_map(fn (array $day): array => TemperatureScore::of($day, $model), $days);
    }
}

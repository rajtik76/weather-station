<?php

declare(strict_types=1);

namespace App\ValueObject;

/**
 * One clock hour's forecasts as the pages list them: temperature and rain on whole hours.
 * The median is the newest forecast's; the range and the rain chance are the widest any forecast of the hour gave.
 * The model is the entrant the newest forecast showed on the horizon nearest the hour, the one its median leans on most.
 *
 * @phpstan-type Reading array{at: int, t: float}
 * @phpstan-type Point array{at: int, values: list<float>}
 */
final readonly class ForecastHours
{
    private const int HOUR_SECONDS = 3600;

    public static function hourStart(int $issuedAt): int
    {
        return intdiv($issuedAt, self::HOUR_SECONDS) * self::HOUR_SECONDS;
    }

    /**
     * Hours not after the newest reading are left out: a forecast from the previous clock hour is still shown for a few windows.
     *
     * @param  list<IssuedForecast>  $forecasts  issued in one clock hour, oldest first
     * @param  list<Reading>  $readings  oldest first
     * @return list<ForecastHour>
     */
    public static function of(array $forecasts, array $readings): array
    {
        if ($forecasts === []) {
            return [];
        }

        $newest = $forecasts[array_key_last($forecasts)];
        $hourStart = self::hourStart($newest->issuedAt);
        $measuredUntil = $readings === [] ? null : $readings[array_key_last($readings)]['at'];
        $ahead = array_filter(
            array_column($newest->horizons, 'hours'),
            fn (int $hours): bool => $measuredUntil === null || $hourStart + $hours * self::HOUR_SECONDS > $measuredUntil,
        );

        return array_values(array_map(function (int $hours) use ($forecasts, $readings, $hourStart): ForecastHour {
            $at = $hourStart + $hours * self::HOUR_SECONDS;
            $estimates = array_map(fn (IssuedForecast $forecast): array => self::estimate($forecast, $readings, $at), $forecasts);

            return new ForecastHour(
                hours: $hours,
                at: $at,
                clock: LocalTime::of($at)->clock(),
                t: round($estimates[array_key_last($estimates)]['mid'], 1),
                tLow: round(min(array_column($estimates, 'low')), 1),
                tHigh: round(max(array_column($estimates, 'high')), 1),
                rain: (int) round(max(array_column($estimates, 'rain')) * 100),
                model: self::shownBy($forecasts[array_key_last($forecasts)], $at),
            );
        }, $ahead));
    }

    /**
     * The forecast's band and rain chance at a moment between its horizons; the reading it was issued from anchors the band before the first.
     *
     * @param  list<Reading>  $readings
     * @return array{low: float, mid: float, high: float, rain: float}
     */
    private static function estimate(IssuedForecast $forecast, array $readings, int $at): array
    {
        $horizons = $forecast->horizons;
        $horizonAt = fn (array $horizon): int => $forecast->issuedAt + $horizon['hours'] * self::HOUR_SECONDS;
        $reading = self::readingOf($forecast, $readings);

        [$low, $mid, $high] = self::interpolate([
            ...($reading === null ? [] : [['at' => $reading['at'], 'values' => [$reading['t'], $reading['t'], $reading['t']]]]),
            ...array_map(fn (array $horizon): array => [
                'at' => $horizonAt($horizon),
                'values' => [$horizon['temperature']['low'], $horizon['temperature']['mid'], $horizon['temperature']['high']],
            ], $horizons),
        ], $at);
        [$rain] = self::interpolate(array_map(fn (array $horizon): array => [
            'at' => $horizonAt($horizon),
            'values' => [$horizon['rain_probability']],
        ], $horizons), $at);

        return ['low' => $low, 'mid' => $mid, 'high' => $high, 'rain' => $rain];
    }

    private static function shownBy(IssuedForecast $forecast, int $at): ?string
    {
        $distance = fn (array $horizon): int => abs($forecast->issuedAt + $horizon['hours'] * self::HOUR_SECONDS - $at);
        $horizons = $forecast->horizons;
        usort($horizons, fn (array $a, array $b): int => $distance($a) <=> $distance($b));

        return $horizons[0]['shown_by'] ?? null;
    }

    /**
     * @param  list<Reading>  $readings
     * @return Reading|null
     */
    private static function readingOf(IssuedForecast $forecast, array $readings): ?array
    {
        $window = intdiv($forecast->issuedAt, ChartWindow::STEP_SECONDS);

        return array_find($readings, fn (array $reading): bool => intdiv($reading['at'], ChartWindow::STEP_SECONDS) === $window);
    }

    /**
     * Linear between neighbours, held flat outside the points.
     *
     * @param  non-empty-list<Point>  $points  oldest first
     * @return list<float>
     */
    private static function interpolate(array $points, int $at): array
    {
        $after = array_find_key($points, fn (array $point): bool => $point['at'] >= $at);

        if ($after === null) {
            return $points[array_key_last($points)]['values'];
        }

        if ($after === 0) {
            return $points[0]['values'];
        }

        $before = $points[$after - 1];
        $share = ($at - $before['at']) / ($points[$after]['at'] - $before['at']);

        return array_map(
            fn (float $from, float $to): float => $from + ($to - $from) * $share,
            $before['values'],
            $points[$after]['values'],
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Forecast;
use App\ValueObject\ChartWindow;
use App\ValueObject\LocalTime;
use App\ValueObject\RainDetector;
use Illuminate\Support\Facades\DB;

/**
 * How the stored forecasts of one sensor did against what it then measured,
 * per horizon. The truth for "n hours ahead" is the ten-minute window that
 * starts n hours after the forecast's own window, the same pairing the
 * models were trained on; a slot the station stamped twice counts by its
 * first reading, as the service reads it.
 *
 * A forecast hour is scored when the window n hours on was measured.
 * Temperature: how often the reading landed inside the 10-90 % range. Rain,
 * on the same hours: the mean chance given when it rained and when it stayed
 * dry. The balcony has no gauge, so the truth is the microphone
 * (RainDetector) - a window of rain it heard within the n hours - and hours
 * it did not listen through are left out of the rain figures only.
 *
 * The temperature is also split by the local hour the forecast was for, all
 * 24 of them, to show when in the day it misses (the morning sun on the
 * shield). An hour is `[percent, forecast hours scored, mean and largest
 * distance of the reading from the middle of the forecast in °C, mean reading
 * minus the middle in °C]` - the last one signed, so a sun that warms the
 * shield shows as the station reading warmer than forecast. Nulls where none
 * was.
 *
 * @phpstan-type Hour array{0: ?float, 1: int, 2: ?float, 3: ?float, 4: ?float}
 * @phpstan-type Score array{hours: int, count: int, temperature: float, rainCount: int, rainCases: int, chanceWhenRain: ?float, chanceWhenDry: ?float, byHour: list<Hour>}
 */
final readonly class ForecastAccuracy
{
    private const int STEP = ChartWindow::STEP_SECONDS;

    /** With no sensor the id is null and nothing matches. */
    public function __construct(private ?int $sensorId) {}

    /**
     * Forecasts issued from $since on; a horizon shows once at least one of
     * them has come true.
     *
     * @return list<Score>
     */
    public function since(int $since): array
    {
        $forecasts = Forecast::query()
            ->where('sensor_id', $this->sensorId)
            ->where('issued_at', '>=', $since)
            ->oldest('issued_at')
            ->get(['issued_at', 'data']);

        if ($forecasts->isEmpty()) {
            return [];
        }

        // As far as the longest stored horizon reaches past the newest forecast.
        $longest = (int) $forecasts->flatMap(fn (Forecast $forecast): array => array_column($forecast->data, 'hours'))->max();
        [$temperatures, $heard, $rainy] = $this->windows($since, $forecasts->last()->issued_at + $longest * 3600);

        /** @var array<int, array{inRange: non-empty-list<array{0: int, 1: bool, 2: float}>, rain: list<float>, dry: list<float>}> $tally */
        $tally = [];

        foreach ($forecasts as $forecast) {
            foreach ($forecast->data as $horizon) {
                $hours = $horizon['hours'];
                $target = $forecast->issued_at + $hours * 3600;
                $truth = $temperatures[$target] ?? null;

                if ($truth === null) {
                    continue;
                }

                $tally[$hours] ??= ['inRange' => [], 'rain' => [], 'dry' => []];
                $band = $horizon['temperature'];
                $tally[$hours]['inRange'][] = [
                    LocalTime::of($target)->hour(),
                    $truth >= $band['low'] && $truth <= $band['high'],
                    $truth - $band['mid'],
                ];

                $rained = $this->rainedWithin($forecast->issued_at, $hours, $heard, $rainy);

                if ($rained !== null) {
                    $tally[$hours][$rained ? 'rain' : 'dry'][] = $horizon['rain_probability'];
                }
            }
        }

        ksort($tally);
        $scores = [];

        foreach ($tally as $hours => $counts) {
            $scores[] = [
                'hours' => $hours,
                'count' => count($counts['inRange']),
                'temperature' => $this->percent(array_column($counts['inRange'], 1)),
                'rainCount' => count($counts['rain']) + count($counts['dry']),
                'rainCases' => count($counts['rain']),
                'chanceWhenRain' => $this->meanPercent($counts['rain']),
                'chanceWhenDry' => $this->meanPercent($counts['dry']),
                'byHour' => $this->byHour($counts['inRange']),
            ];
        }

        return $scores;
    }

    /**
     * One read of the windows: each slot's temperature in °C, the slots the
     * microphone listened through, and the ones it heard rain in. A slot
     * stamped twice keeps its first reading, as the service's grid does.
     *
     * @return array{0: array<int, float>, 1: array<int, true>, 2: array<int, true>}
     */
    private function windows(int $since, int $until): array
    {
        $rows = DB::query()
            ->fromSub(
                DB::table('measurements')
                    ->where('sensor_id', $this->sensorId)
                    ->whereBetween('timestamp', [$since, $until + self::STEP])
                    ->selectRaw('(timestamp / ?::int) * ?::int AS slot', [self::STEP, self::STEP])
                    ->addSelect('timestamp')
                    ->selectRaw("(data->>'temperature')::int / 100.0 AS temperature")
                    ->selectRaw("data->'noise'->'bands' AS bands"),
                'window',
            )
            ->selectRaw('DISTINCT ON (slot) slot, temperature, bands')
            ->orderBy('slot')
            ->orderBy('timestamp')
            ->get();

        $temperatures = [];
        $heard = [];
        $rainy = [];

        foreach ($rows as $row) {
            $slot = (int) $row->slot;
            $temperatures[$slot] = (float) $row->temperature;

            if ($row->bands === null) {
                continue;
            }

            $heard[$slot] = true;
            /** @var list<int> $bands */
            $bands = json_decode((string) $row->bands, true, flags: JSON_THROW_ON_ERROR);

            if (RainDetector::hears(array_map(fn (int $level): float => $level / 100, $bands))) {
                $rainy[$slot] = true;
            }
        }

        return [$temperatures, $heard, $rainy];
    }

    /**
     * Whether rain was heard in the windows after the forecast's up to n hours
     * on; null unless the microphone heard every one of them.
     *
     * @param  array<int, true>  $heard
     * @param  array<int, true>  $rainy
     */
    private function rainedWithin(int $issuedAt, int $hours, array $heard, array $rainy): ?bool
    {
        $rained = false;

        for ($slot = $issuedAt + self::STEP; $slot <= $issuedAt + $hours * 3600; $slot += self::STEP) {
            if (! isset($heard[$slot])) {
                return null;
            }

            $rained = $rained || isset($rainy[$slot]);
        }

        return $rained;
    }

    /**
     * @param  list<array{0: int, 1: bool, 2: float}>  $hits  local hour, landed in range, reading minus the middle
     * @return list<Hour>
     */
    private function byHour(array $hits): array
    {
        $grouped = [];

        foreach ($hits as [$hour, $right, $difference]) {
            $grouped[$hour][] = [$right, $difference];
        }

        $hours = [];

        for ($hour = 0; $hour < 24; $hour++) {
            if (! isset($grouped[$hour])) {
                $hours[] = [null, 0, null, null, null];

                continue;
            }

            $count = count($grouped[$hour]);
            $percent = $this->percent(array_column($grouped[$hour], 0));
            $differences = array_column($grouped[$hour], 1);
            $distances = array_map(abs(...), $differences);

            $hours[] = [
                $percent,
                $count,
                round(array_sum($distances) / $count, 2),
                round(max($distances), 2),
                round(array_sum($differences) / $count, 2),
            ];
        }

        return $hours;
    }

    /**
     * @param  non-empty-list<bool>  $rights
     */
    private function percent(array $rights): float
    {
        return round(100 * count(array_filter($rights)) / count($rights));
    }

    /**
     * @param  list<float>  $chances  0-1
     */
    private function meanPercent(array $chances): ?float
    {
        return $chances === [] ? null : round(100 * array_sum($chances) / count($chances));
    }
}

<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Forecast;
use App\ValueObject\ChartWindow;
use App\ValueObject\RainDetector;
use Illuminate\Support\Facades\DB;

/**
 * How the stored forecasts of one sensor did against what it then measured,
 * per horizon. The truth for "n hours ahead" is the ten-minute window that
 * starts n hours after the forecast's own window, the same pairing the
 * models were trained on; a slot the station stamped twice counts by its
 * first reading, as the service reads it.
 *
 * A forecast hour is scored when both its own window and the one n hours on
 * were measured. Temperature: how often the reading landed inside the 10-90 %
 * range (the models aim at 80 %), the mean distance from the median, and
 * beside it the error of assuming nothing changes. Rain, on the same hours:
 * the balcony has no gauge, so the truth is the microphone (RainDetector) -
 * a window of rain it heard within the n hours - and hours it did not listen
 * through are left out of the rain figures only.
 *
 * @phpstan-type Score array{hours: int, count: int, inRange: float, error: float, unchangedError: float, rainCount: int, rainCases: int, chanceWhenRain: ?float, chanceWhenDry: ?float}
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

        /** @var array<int, array{inRange: list<bool>, errors: list<float>, unchanged: list<float>, rain: list<float>, dry: list<float>}> $tally */
        $tally = [];

        foreach ($forecasts as $forecast) {
            $now = $temperatures[$forecast->issued_at] ?? null;

            foreach ($forecast->data as $horizon) {
                $hours = $horizon['hours'];
                $truth = $temperatures[$forecast->issued_at + $hours * 3600] ?? null;

                if ($now === null || $truth === null) {
                    continue;
                }

                $tally[$hours] ??= ['inRange' => [], 'errors' => [], 'unchanged' => [], 'rain' => [], 'dry' => []];
                $band = $horizon['temperature'];
                $tally[$hours]['inRange'][] = $truth >= $band['low'] && $truth <= $band['high'];
                $tally[$hours]['errors'][] = abs($truth - $band['mid']);
                $tally[$hours]['unchanged'][] = abs($truth - $now);

                $rained = $this->rainedWithin($forecast->issued_at, $hours, $heard, $rainy);

                if ($rained !== null) {
                    $tally[$hours][$rained ? 'rain' : 'dry'][] = $horizon['rain_probability'];
                }
            }
        }

        ksort($tally);
        $scores = [];

        foreach ($tally as $hours => $counts) {
            $count = count($counts['errors']);
            $scores[] = [
                'hours' => $hours,
                'count' => $count,
                'inRange' => round(100 * count(array_filter($counts['inRange'])) / $count),
                'error' => round(array_sum($counts['errors']) / $count, 2),
                'unchangedError' => round(array_sum($counts['unchanged']) / $count, 2),
                'rainCount' => count($counts['rain']) + count($counts['dry']),
                'rainCases' => count($counts['rain']),
                'chanceWhenRain' => $this->meanPercent($counts['rain']),
                'chanceWhenDry' => $this->meanPercent($counts['dry']),
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
     * @param  list<float>  $chances  0-1
     */
    private function meanPercent(array $chances): ?float
    {
        return $chances === [] ? null : round(100 * array_sum($chances) / count($chances));
    }
}

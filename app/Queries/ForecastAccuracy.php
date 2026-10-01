<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Forecast;
use App\ValueObject\ChartWindow;
use App\ValueObject\ForecastScore;
use App\ValueObject\LocalTime;
use App\ValueObject\ModelName;
use App\ValueObject\RainDetector;
use Illuminate\Support\Facades\DB;

/**
 * Scores one sensor's stored forecasts against what it then measured, per horizon.
 * Truth for "n hours ahead" is the ten-minute window starting n hours after the
 * forecast's own window (the models' training pairing); a slot stamped twice
 * counts by its first reading.
 *
 * Each horizon is scored as shown and as `base` (before station correction), both
 * on hours that have a base. Skill is how much smaller the miss is than the naive
 * guess (the forecast window's reading carried n hours on): 0 is no better, below
 * 0 worse. Misses are summed before dividing; a forecast whose own window went
 * unmeasured is left out of skill and misses only.
 *
 * Rain truth is the microphone (RainDetector) within the n hours; hours it did not
 * listen through are left out of the rain figures only. Days carry the model name
 * or correction version that took over that day; a null correction is skipped.
 * `byHour` buckets the shown forecast by the local hour it was for.
 *
 * A Day is `[date, forecast hours scored, skill in %, mean miss and mean naive
 * miss in °C, percent in range, mean range width in °C, base: skill, miss,
 * percent in range, width, model that took over, correction that took over]`.
 * An Hour is `[percent in range, hours scored, mean and largest distance of the
 * reading from the forecast middle in °C, mean signed reading minus middle in °C]`.
 * Nulls where nothing was scored.
 *
 * @phpstan-type Hour array{0: ?float, 1: int, 2: ?float, 3: ?float, 4: ?float}
 * @phpstan-type Day array{0: string, 1: int, 2: ?float, 3: ?float, 4: ?float, 5: ?float, 6: ?float, 7: ?float, 8: ?float, 9: ?float, 10: ?float, 11: ?string, 12: ?int}
 * @phpstan-type Figures array{count: int, skill: ?float, error: ?float, naive: ?float, inRange: float, width: float}
 * @phpstan-type Rain array{count: int, cases: int, chanceWhenRain: ?float, chanceWhenDry: ?float}
 * @phpstan-type Score array{hours: int, days: list<Day>, corrected: Figures, base: ?Figures, shown: Figures, rain: Rain, byHour: list<Hour>}
 * @phpstan-type Miss array{inRange: bool, difference: float, width: float}
 *
 * @phpstan-import-type Band from Forecast
 *
 * @phpstan-type Scored array{issuedAt: int, date: string, hour: int, naive: ?float, rained: ?bool, chance: float, corrected: Miss, base: ?Miss}
 */
final readonly class ForecastAccuracy
{
    private const int STEP = ChartWindow::STEP_SECONDS;

    /** Null id matches nothing. */
    public function __construct(private ?int $sensorId) {}

    /**
     * Forecasts issued from $since on; a horizon shows once one has come true.
     *
     * @return list<Score>
     */
    public function since(int $since): array
    {
        $forecasts = Forecast::query()
            ->where('sensor_id', $this->sensorId)
            ->where('issued_at', '>=', $since)
            ->oldest('issued_at')
            ->get(['issued_at', 'model', 'correction', 'data']);

        if ($forecasts->isEmpty()) {
            return [];
        }

        // Reach of the longest stored horizon past the newest forecast.
        $longest = (int) $forecasts->flatMap(fn (Forecast $forecast): array => array_column($forecast->data, 'hours'))->max();
        [$temperatures, $heard, $rainy] = $this->windows($since, $forecasts->last()->issued_at + $longest * 3600);

        /** @var array<int, non-empty-list<Scored>> $tally */
        $tally = [];
        /** @var array<string, array{model?: string, correction?: int}> $tookOver what took over, by the day it did */
        $tookOver = [];
        $previousModel = null;
        $previousCorrection = null;

        foreach ($forecasts as $forecast) {
            $now = $temperatures[$forecast->issued_at] ?? null;
            $date = LocalTime::of($forecast->issued_at)->date();

            if ($previousModel !== null && $forecast->model !== $previousModel) {
                $tookOver[$date]['model'] = ModelName::of($forecast->model);
            }

            if ($forecast->correction !== null) {
                if ($previousCorrection !== null && $forecast->correction !== $previousCorrection) {
                    $tookOver[$date]['correction'] = $forecast->correction;
                }

                $previousCorrection = $forecast->correction;
            }

            $previousModel = $forecast->model;

            foreach ($forecast->data as $horizon) {
                $hours = $horizon['hours'];
                $target = $forecast->issued_at + $hours * 3600;
                $truth = $temperatures[$target] ?? null;

                if ($truth === null) {
                    continue;
                }

                $tally[$hours][] = [
                    'issuedAt' => $forecast->issued_at,
                    'date' => $date,
                    'hour' => LocalTime::of($target)->hour(),
                    'naive' => $now === null ? null : abs($truth - $now),
                    'rained' => $this->rainedWithin($forecast->issued_at, $hours, $heard, $rainy),
                    'chance' => $horizon['rain_probability'],
                    'corrected' => ForecastScore::miss($truth, $horizon['temperature']),
                    'base' => isset($horizon['base']) ? ForecastScore::miss($truth, $horizon['base']['temperature']) : null,
                ];
            }
        }

        ksort($tally);
        $scores = [];

        foreach ($tally as $hours => $scored) {
            $scores[] = ForecastScore::of($hours, $scored, $tookOver, $forecasts->last()->issued_at);
        }

        return $scores;
    }

    /**
     * Per slot: temperature in °C, slots the microphone listened through, slots it heard rain in.
     * A slot stamped twice keeps its first reading, as the service's grid does.
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

            if (RainDetector::hearsStored($bands)) {
                $rainy[$slot] = true;
            }
        }

        return [$temperatures, $heard, $rainy];
    }

    /**
     * Null unless the microphone heard every window up to n hours on.
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
}

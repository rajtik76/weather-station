<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Forecast;
use App\ValueObject\ChartWindow;
use App\ValueObject\DayScore;
use App\ValueObject\ExperimentalForecastScore;
use App\ValueObject\ForecastScore;
use App\ValueObject\HourOfDayScore;
use App\ValueObject\LocalTime;
use App\ValueObject\ModelName;
use App\ValueObject\RainDetector;
use App\ValueObject\ScoreFigures;
use App\ValueObject\TodaySlot;
use App\ValueObject\TodayTrace;
use Illuminate\Support\Facades\DB;

/**
 * Scores one sensor's stored forecasts per horizon, shown and `base`, on hours that have a base.
 * Truth for "n hours ahead" is the ten-minute window starting n hours after the forecast's own window;
 * a slot stamped twice counts by its first reading.
 * Skill is the miss relative to the naive guess (the forecast window's reading carried n hours on).
 * Misses are summed before dividing; a forecast with an unmeasured own window is left out of skill and misses.
 * Rain truth is RainDetector within the n hours; hours without microphone data are left out of the rain figures.
 *
 * @phpstan-type Rain array{count: int, cases: int, chanceWhenRain: ?float, chanceWhenDry: ?float}
 * @phpstan-type Score array{hours: int, days: list<DayScore>, corrected: ScoreFigures, base: ?ScoreFigures, shown: ScoreFigures, rain: Rain, byHour: array<string, list<HourOfDayScore>>, today?: list<TodaySlot>, experiment?: array{version: string, synthetic: bool, since: int, covers: list<string>}}
 * @phpstan-type Miss array{inRange: bool, difference: float, width: float}
 *
 * @phpstan-import-type Band from Forecast
 * @phpstan-import-type Horizon from Forecast
 *
 * @phpstan-type Scored array{issuedAt: int, date: string, target: int, hour: int, naive: ?float, rained: ?bool, chance: float, corrected: Miss, base: ?Miss, experiment?: array{version: string, synthetic: bool, miss: Miss}}
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
        /** @var array<int, list<Horizon>> $byIssue */
        $byIssue = [];

        foreach ($forecasts as $forecast) {
            $byIssue[$forecast->issued_at] = $forecast->data;
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
                    'target' => $target,
                    'hour' => LocalTime::of($target)->hour(),
                    'naive' => $now === null ? null : abs($truth - $now),
                    'rained' => $this->rainedWithin($forecast->issued_at, $hours, $heard, $rainy),
                    'chance' => $horizon['rain_probability'],
                    'corrected' => ForecastScore::miss($truth, $horizon['temperature']),
                    'base' => isset($horizon['base']) ? ForecastScore::miss($truth, $horizon['base']['temperature']) : null,
                    ...(isset($horizon['experiment']) ? ['experiment' => [
                        'version' => $horizon['experiment']['version'],
                        'synthetic' => $horizon['experiment']['synthetic'] ?? false,
                        'miss' => ForecastScore::miss($truth, $horizon['experiment']['temperature']),
                    ]] : []),
                ];
            }
        }

        ksort($tally);
        $scores = [];
        $lastIssued = $forecasts->last()->issued_at;
        $latest = (int) array_key_last($temperatures);
        $now = now()->getTimestamp();

        foreach ($tally as $hours => $scored) {
            $score = ExperimentalForecastScore::onto(ForecastScore::of($hours, $scored, $tookOver, $lastIssued, $now), $scored, $tookOver, $lastIssued, $now);
            $scores[] = [...$score, 'today' => TodayTrace::of($hours, $byIssue, $temperatures, $now, $latest, $score['experiment']['version'] ?? null)];
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

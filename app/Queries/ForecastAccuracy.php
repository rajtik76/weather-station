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
 * A forecast hour is scored when the window n hours on was measured, twice:
 * as shown, and as the base model made it before the station correction
 * (`base`, missing on forecasts stored before the service returned it and
 * on those of a model the service no longer runs). Both are scored on the
 * hours that have a base, so the gap between them is what the correction
 * has learnt from the station's own misses.
 *
 * The headline is the skill: how much smaller the forecast's miss was than
 * the naive guess's, the reading in the forecast's own window taken as the
 * temperature n hours on. Zero is no better than the guess, below zero worse.
 * The misses are summed before they are divided, so a calm hour does not
 * weigh as much as a front. A forecast whose own window went unmeasured has
 * no guess to beat and is left out of the skill and the misses only.
 *
 * Beside it, how often the reading landed inside the 10-90 % range - eight
 * in ten is on target, all of them a range drawn too wide - and how wide the
 * range was. Rain, on the same hours, which the correction does not touch:
 * the mean chance given when it rained and when it stayed dry. The balcony
 * has no gauge, so the truth is the microphone (RainDetector) - a window of
 * rain it heard within the n hours - and hours it did not listen through are
 * left out of the rain figures only.
 *
 * Each horizon comes over the whole span and by the local day the forecast
 * was made, so the skill reads as a line through time. A day a new model
 * took over carries its name - the moment it was trained, as the service
 * stamps it - so a retrain shows where it happened; a day the correction's
 * logic changed carries its new version. A forecast stored before versions
 * were kept (null) is skipped, not taken for a change. The shown forecast also
 * comes by the local hour it was for, all 24 of them, to show when in the
 * day it misses (the morning sun on the shield).
 *
 * A day is `[date, forecast hours scored, skill in %, mean miss and mean
 * naive miss in °C, percent in range, mean range width in °C, the base
 * model's skill, mean miss, percent in range and range width, the model that
 * took over or null, the correction version that took over or null]`, nulls
 * but the date and the changes for a day with none. An hour is
 * `[percent in range, forecast hours scored, mean and largest distance of the
 * reading from the middle of the forecast in °C, mean reading minus the
 * middle in °C]` - the last one signed, so a sun that warms the shield shows
 * as the station reading warmer than forecast. Nulls where none was.
 *
 * @phpstan-type Hour array{0: ?float, 1: int, 2: ?float, 3: ?float, 4: ?float}
 * @phpstan-type Day array{0: string, 1: int, 2: ?float, 3: ?float, 4: ?float, 5: ?float, 6: ?float, 7: ?float, 8: ?float, 9: ?float, 10: ?float, 11: ?string, 12: ?int}
 * @phpstan-type Figures array{count: int, skill: ?float, error: ?float, naive: ?float, inRange: float, width: float}
 * @phpstan-type Rain array{count: int, cases: int, chanceWhenRain: ?float, chanceWhenDry: ?float}
 * @phpstan-type Score array{hours: int, days: list<Day>, corrected: Figures, base: ?Figures, rain: Rain, byHour: list<Hour>}
 * @phpstan-type Miss array{inRange: bool, difference: float, width: float}
 *
 * @phpstan-import-type Band from Forecast
 *
 * @phpstan-type Scored array{issuedAt: int, date: string, hour: int, naive: ?float, rained: ?bool, chance: float, corrected: Miss, base: ?Miss}
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
            ->get(['issued_at', 'model', 'correction', 'data']);

        if ($forecasts->isEmpty()) {
            return [];
        }

        // As far as the longest stored horizon reaches past the newest forecast.
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
                $tookOver[$date]['model'] = $this->modelName($forecast->model);
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
                    'corrected' => $this->miss($truth, $horizon['temperature']),
                    'base' => isset($horizon['base']) ? $this->miss($truth, $horizon['base']['temperature']) : null,
                ];
            }
        }

        ksort($tally);
        $scores = [];

        foreach ($tally as $hours => $scored) {
            $scores[] = [
                'hours' => $hours,
                'days' => $this->byDay($scored, $tookOver, $forecasts->last()->issued_at),
                ...$this->compared($scored),
                'rain' => $this->rain($scored),
                'byHour' => $this->byHour($scored),
            ];
        }

        return $scores;
    }

    /**
     * @param  Band  $band
     * @return Miss
     */
    private function miss(float $truth, array $band): array
    {
        return [
            'inRange' => $truth >= $band['low'] && $truth <= $band['high'],
            'difference' => $truth - $band['mid'],
            'width' => $band['high'] - $band['low'],
        ];
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
     * Every local day from the first forecast scored to the last one issued,
     * a day with none scored included, so a gap in the service shows as a
     * gap in the line and a model that took over today is marked before its
     * first forecast comes true.
     *
     * @param  non-empty-list<Scored>  $scored  oldest first
     * @param  array<string, array{model?: string, correction?: int}>  $tookOver
     * @return list<Day>
     */
    private function byDay(array $scored, array $tookOver, int $lastIssued): array
    {
        $grouped = [];

        foreach ($scored as $one) {
            $grouped[$one['date']][] = $one;
        }

        $days = [];

        foreach (LocalTime::of($scored[0]['issuedAt'])->daysThrough(LocalTime::of($lastIssued)) as $day) {
            $date = $day->date();

            if (! isset($grouped[$date])) {
                $days[] = [$date, 0, null, null, null, null, null, null, null, null, null, $tookOver[$date]['model'] ?? null, $tookOver[$date]['correction'] ?? null];

                continue;
            }

            ['corrected' => $corrected, 'base' => $base] = $this->compared($grouped[$date]);
            $days[] = [
                $date,
                $corrected['count'],
                $corrected['skill'],
                $corrected['error'],
                $corrected['naive'],
                $corrected['inRange'],
                $corrected['width'],
                $base['skill'] ?? null,
                $base['error'] ?? null,
                $base['inRange'] ?? null,
                $base['width'] ?? null,
                $tookOver[$date]['model'] ?? null,
                $tookOver[$date]['correction'] ?? null,
            ];
        }

        return $days;
    }

    /**
     * @param  list<Scored>  $scored
     * @return list<Hour>
     */
    private function byHour(array $scored): array
    {
        $grouped = [];

        foreach ($scored as $one) {
            $grouped[$one['hour']][] = $one['corrected'];
        }

        $hours = [];

        for ($hour = 0; $hour < 24; $hour++) {
            if (! isset($grouped[$hour])) {
                $hours[] = [null, 0, null, null, null];

                continue;
            }

            $count = count($grouped[$hour]);
            $differences = array_column($grouped[$hour], 'difference');
            $distances = array_map(abs(...), $differences);

            $hours[] = [
                $this->percent(array_column($grouped[$hour], 'inRange')),
                $count,
                round(array_sum($distances) / $count, 2),
                round(max($distances), 2),
                round(array_sum($differences) / $count, 2),
            ];
        }

        return $hours;
    }

    /**
     * The forecast as shown and before the correction, on the same hours:
     * once any hour has a base, the shown one is scored only on the hours
     * that have one too, so the gap between them is the correction's and not
     * a different mix of days. Without any, the shown forecast on them all.
     *
     * @param  non-empty-list<Scored>  $scored
     * @return array{corrected: Figures, base: ?Figures}
     */
    private function compared(array $scored): array
    {
        $withBase = array_values(array_filter($scored, fn (array $one): bool => $one['base'] !== null));

        if ($withBase === []) {
            return ['corrected' => $this->figures($scored, 'corrected'), 'base' => null];
        }

        return ['corrected' => $this->figures($withBase, 'corrected'), 'base' => $this->figures($withBase, 'base')];
    }

    /**
     * The forecast as shown (`corrected`) or before the correction (`base`),
     * over the hours that have it; null when none has.
     *
     * @param  list<Scored>  $scored
     * @param  'corrected'|'base'  $which
     * @return ($which is 'corrected' ? Figures : ?Figures)
     */
    private function figures(array $scored, string $which): ?array
    {
        $misses = [];
        $paired = [];

        foreach ($scored as $one) {
            if ($one[$which] === null) {
                continue;
            }

            $misses[] = $one[$which];

            if ($one['naive'] !== null) {
                $paired[] = [abs($one[$which]['difference']), $one['naive']];
            }
        }

        if ($misses === []) {
            return null;
        }

        $missed = array_sum(array_column($paired, 0));
        $guessMissed = array_sum(array_column($paired, 1));

        return [
            'count' => count($misses),
            // A guess that never missed leaves nothing to beat.
            'skill' => $guessMissed > 0 ? round(100 * (1 - $missed / $guessMissed)) : null,
            'error' => $paired === [] ? null : round($missed / count($paired), 2),
            'naive' => $paired === [] ? null : round($guessMissed / count($paired), 2),
            'inRange' => $this->percent(array_column($misses, 'inRange')),
            'width' => round(array_sum(array_column($misses, 'width')) / count($misses), 2),
        ];
    }

    /**
     * @param  list<Scored>  $scored
     * @return Rain
     */
    private function rain(array $scored): array
    {
        $rain = array_column(array_filter($scored, fn (array $one): bool => $one['rained'] === true), 'chance');
        $dry = array_column(array_filter($scored, fn (array $one): bool => $one['rained'] === false), 'chance');

        return [
            'count' => count($rain) + count($dry),
            'cases' => count($rain),
            'chanceWhenRain' => $this->meanPercent($rain),
            'chanceWhenDry' => $this->meanPercent($dry),
        ];
    }

    /**
     * The service stamps a model with the ISO moment it was trained; anything
     * else prints as it came. Checked by shape first: strtotime() reads even
     * "v3.4.0" as a time.
     */
    private function modelName(string $model): string
    {
        $trainedAt = preg_match('/^\d{4}-\d{2}-\d{2}T/', $model) === 1 ? strtotime($model) : false;

        return $trainedAt === false ? $model : LocalTime::of($trainedAt)->stamp();
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

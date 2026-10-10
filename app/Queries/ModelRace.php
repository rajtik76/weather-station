<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\RaceBlock;
use App\Models\Forecast;
use App\ValueObject\LocalTime;
use App\ValueObject\RaceDay;
use App\ValueObject\RaceEntrants;

/**
 * Daily race points of one sensor's stored forecasts, by the local day and block of each target: every entrant's
 * mean absolute miss of its median in °C. Only targets every entrant forecast count. Truth for "n hours ahead" is the
 * ten-minute slot n hours after the forecast's own, as ForecastAccuracy scores it.
 */
final readonly class ModelRace
{
    private const int HOUR = 3600;

    /** The service forecasts 1 to 6 h ahead. */
    private const int LONGEST_HORIZON_SECONDS = 6 * self::HOUR;

    /** Null id matches nothing. */
    public function __construct(private ?int $sensorId) {}

    /**
     * Local days from $first up to, not including, $until (both midnights), oldest first.
     *
     * @return list<RaceDay>
     */
    public function days(LocalTime $first, LocalTime $until): array
    {
        $temperatures = new SlotTemperatures($this->sensorId)->between($first->timestamp, $until->timestamp);
        /** @var array<string, array<string, array<string, array{float, int}>>> $tally date, block, entrant: summed miss and count */
        $tally = [];

        $forecasts = Forecast::query()
            ->where('sensor_id', $this->sensorId)
            ->where('issued_at', '>=', $first->timestamp - self::LONGEST_HORIZON_SECONDS)
            ->where('issued_at', '<', $until->timestamp)
            ->oldest('issued_at')
            ->get(['issued_at', 'data']);

        foreach ($forecasts as $forecast) {
            foreach ($forecast->data as $horizon) {
                $target = $forecast->issued_at + $horizon['hours'] * self::HOUR;
                $truth = $temperatures[$target] ?? null;

                if ($truth === null || ! RaceEntrants::areAllIn($horizon)) {
                    continue;
                }

                $date = LocalTime::of($target)->date();
                $block = RaceBlock::at($target)->value;

                foreach (RaceEntrants::bands($horizon) as $name => $band) {
                    [$sum, $count] = $tally[$date][$block][$name] ?? [0.0, 0];
                    $tally[$date][$block][$name] = [$sum + abs($band['mid'] - $truth), $count + 1];
                }
            }
        }

        return array_map(function (LocalTime $day) use ($tally): RaceDay {
            $date = $day->date();

            return new RaceDay($date, array_map(
                fn (array $entrants): array => array_map(fn (array $miss): float => round($miss[0] / $miss[1], 3), $entrants),
                $tally[$date] ?? [],
            ));
        }, $first->daysThrough(LocalTime::of($until->timestamp - 1)));
    }
}

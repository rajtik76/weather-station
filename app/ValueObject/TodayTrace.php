<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Models\Forecast;

/**
 * Today's slots from local midnight through the newest measured one, each with the forecast issued one horizon before it; none while today has no reading.
 *
 * @phpstan-import-type Horizon from Forecast
 */
final readonly class TodayTrace
{
    /**
     * @param  array<int, list<Horizon>>  $forecasts  by issue slot
     * @param  array<int, float>  $temperatures  by slot
     * @param  ?string  $version  the experiment version drawn; another is left out
     * @return list<TodaySlot>
     */
    public static function of(int $hours, array $forecasts, array $temperatures, int $now, int $latest, ?string $version): array
    {
        $slots = [];

        for ($at = LocalTime::of($now)->midnight()->timestamp; $at <= min($latest, $now); $at += ChartWindow::STEP_SECONDS) {
            $horizon = array_find($forecasts[$at - $hours * 3600] ?? [], fn (array $horizon): bool => $horizon['hours'] === $hours);
            $experiment = $horizon['experiment'] ?? null;

            $slots[] = new TodaySlot(
                at: $at,
                clock: LocalTime::of($at)->clock(),
                measured: $temperatures[$at] ?? null,
                shown: $horizon['temperature'] ?? null,
                base: $horizon['base']['temperature']['mid'] ?? null,
                experiment: $experiment !== null && $experiment['version'] === $version ? $experiment['temperature']['mid'] : null,
            );
        }

        return $slots;
    }
}

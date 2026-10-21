<?php

declare(strict_types=1);

namespace App\ValueObject;

/** Rainy windows of an index-spaced trace as horizontal stretches, in percent of its width. */
final readonly class RainMarks
{
    /**
     * Each window spans half a step either side of its point, so adjacent rainy windows join. A lone window
     * spans the width, as Trace::spanning() draws it.
     *
     * @param  list<?bool>  $rain  one verdict per trace point, null without a spectrum
     * @return list<array{from: float, to: float}>
     */
    public static function stretches(array $rain): array
    {
        if (count($rain) === 1) {
            $rain = [$rain[0], $rain[0]];
        }

        $step = 100 / max(1, count($rain) - 1);
        $stretches = [];
        $from = null;
        $to = 0.0;

        foreach ([...$rain, null] as $index => $heard) {
            if ($heard === true) {
                $from ??= round(max(0.0, ($index - 0.5) * $step), 2);
                $to = round(min(100.0, ($index + 0.5) * $step), 2);

                continue;
            }

            if ($from !== null) {
                $stretches[] = ['from' => $from, 'to' => $to];
                $from = null;
            }
        }

        return $stretches;
    }
}

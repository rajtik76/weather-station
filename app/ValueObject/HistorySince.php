<?php

declare(strict_types=1);

namespace App\ValueObject;

use InvalidArgumentException;

/**
 * `forecast.history_since` as local midnight: corrections learn only from then on.
 */
final readonly class HistorySince
{
    /** Null when unset; an unparsable date is reported and ignored. */
    public static function fromConfig(): ?int
    {
        $since = config('forecast.history_since');

        if (! is_string($since) || $since === '') {
            return null;
        }

        $midnight = LocalTime::midnightOf($since);

        if (! $midnight instanceof LocalTime) {
            report(new InvalidArgumentException("FORECAST_HISTORY_SINCE is not a Y-m-d date: {$since}"));

            return null;
        }

        return $midnight->timestamp;
    }
}

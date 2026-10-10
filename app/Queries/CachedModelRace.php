<?php

declare(strict_types=1);

namespace App\Queries;

use App\ValueObject\LocalTime;
use App\ValueObject\RaceStandings;
use Illuminate\Support\Facades\Cache;

/**
 * The model race over the RACE_DAYS local days before today, worked out once per local day and sensor.
 * One key per sensor with the day inside the value: the database store only deletes expired rows it reads.
 */
final readonly class CachedModelRace
{
    public const int RACE_DAYS = 14;

    /** Bump when RaceStandings or RaceDay change shape so a deploy never reads the old one. */
    private const int SHAPE = 1;

    private const int KEPT_DAYS = 2;

    /** Null id matches nothing. */
    public function __construct(private ?int $sensorId) {}

    public function standings(): RaceStandings
    {
        $today = LocalTime::of(now()->getTimestamp())->midnight();
        $cached = Cache::get($this->key());

        if (is_array($cached) && ($cached['shape'] ?? null) === self::SHAPE && ($cached['day'] ?? null) === $today->timestamp && ($cached['standings'] ?? null) instanceof RaceStandings) {
            return $cached['standings'];
        }

        // Noon of the first day: a day either side of a DST change still lands on its midnight.
        $first = LocalTime::of($today->timestamp - self::RACE_DAYS * 86400 + 43200)->midnight();
        $standings = new RaceStandings(new ModelRace($this->sensorId)->days($first, $today));
        Cache::put($this->key(), ['shape' => self::SHAPE, 'day' => $today->timestamp, 'standings' => $standings], now()->addDays(self::KEPT_DAYS));

        return $standings;
    }

    /** For a change to stored forecasts the race has already scored. */
    public function forget(): void
    {
        Cache::forget($this->key());
    }

    private function key(): string
    {
        return "model-race:{$this->sensorId}";
    }
}

<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Forecast;
use Illuminate\Support\Facades\Cache;

/**
 * ForecastAccuracy cached until the next forecast, 15 minutes at most.
 * One key per sensor with the forecast and span inside the value: the database
 * store only deletes expired rows it reads, so a key per forecast grows the table.
 *
 * @phpstan-import-type Score from ForecastAccuracy
 */
final readonly class CachedForecastAccuracy
{
    /** Bump when Score changes shape so a deploy never reads the old one. */
    private const int SHAPE = 13;

    private const int TTL_MINUTES = 15;

    /** Null id matches nothing. */
    public function __construct(private ?int $sensorId) {}

    /**
     * Empty until a forecast has come true.
     *
     * @return list<Score>
     */
    public function lastDays(int $days): array
    {
        $newest = Forecast::query()->where('sensor_id', $this->sensorId)->max('issued_at');

        if ($newest === null) {
            return [];
        }

        $cached = Cache::get($this->key());

        if (is_array($cached) && ($cached['shape'] ?? null) === self::SHAPE && ($cached['issuedAt'] ?? null) === $newest && ($cached['days'] ?? null) === $days) {
            return $cached['scores'];
        }

        $scores = new ForecastAccuracy($this->sensorId)->since(now()->subDays($days)->getTimestamp());
        Cache::put($this->key(), ['shape' => self::SHAPE, 'issuedAt' => $newest, 'days' => $days, 'scores' => $scores], now()->addMinutes(self::TTL_MINUTES));

        return $scores;
    }

    /** For a change to stored forecasts that leaves the newest one as it was. */
    public function forget(): void
    {
        Cache::forget($this->key());
    }

    private function key(): string
    {
        return "forecast-accuracy:{$this->sensorId}";
    }
}

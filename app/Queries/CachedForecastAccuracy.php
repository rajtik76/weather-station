<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Forecast;
use Illuminate\Support\Facades\Cache;

/**
 * ForecastAccuracy kept until the next forecast: it comes with the upload
 * that completes new scores. Fifteen minutes at most, for when the service
 * is down and none comes.
 *
 * One key per sensor, the forecast it was scored at inside the value: a key
 * per forecast is never read again once the next one comes, and the
 * database store only deletes an expired row it reads, so the table grew by
 * a row every ten minutes. The span scored is in the value too, so a
 * caller asking for another one does not read this one's scores.
 *
 * @phpstan-import-type Score from ForecastAccuracy
 */
final readonly class CachedForecastAccuracy
{
    /**
     * Stored with the scores: bump it when Score changes shape, so a deploy
     * never reads the old one. In the value, not the key - a key per shape
     * would leave the old row behind for good.
     */
    private const int SHAPE = 7;

    private const int TTL_MINUTES = 15;

    /** With no sensor the id is null and nothing matches. */
    public function __construct(private ?int $sensorId) {}

    /**
     * The last $days of forecasts scored; empty until one has come true.
     *
     * @return list<Score>
     */
    public function lastDays(int $days): array
    {
        $newest = Forecast::query()->where('sensor_id', $this->sensorId)->max('issued_at');

        if ($newest === null) {
            return [];
        }

        $key = "forecast-accuracy:{$this->sensorId}";
        $cached = Cache::get($key);

        if (is_array($cached) && ($cached['shape'] ?? null) === self::SHAPE && ($cached['issuedAt'] ?? null) === $newest && ($cached['days'] ?? null) === $days) {
            return $cached['scores'];
        }

        $scores = new ForecastAccuracy($this->sensorId)->since(now()->subDays($days)->getTimestamp());
        Cache::put($key, ['shape' => self::SHAPE, 'issuedAt' => $newest, 'days' => $days, 'scores' => $scores], now()->addMinutes(self::TTL_MINUTES));

        return $scores;
    }
}

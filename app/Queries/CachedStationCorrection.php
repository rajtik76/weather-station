<?php

declare(strict_types=1);

namespace App\Queries;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * One sensor's station correction, cached for a day and refitted when `since` changes.
 * One key per sensor with `since` inside the value: the database store only deletes expired rows it reads.
 *
 * @phpstan-import-type Fitted from ForecastService
 */
final readonly class CachedStationCorrection
{
    private const int REFIT_AFTER_HOURS = 24;

    public function __construct(private int $sensorId, private ?int $since) {}

    /**
     * @param  Closure(): Fitted  $fit
     * @return Fitted
     */
    public function current(Closure $fit): array
    {
        /** @var array{since: ?int, fitted: Fitted}|null $cached */
        $cached = Cache::get($this->key());

        if ($cached !== null && $cached['since'] === $this->since) {
            return $cached['fitted'];
        }

        return $this->refit($fit);
    }

    /**
     * @param  Closure(): Fitted  $fit
     * @return Fitted
     */
    public function refit(Closure $fit): array
    {
        $fitted = $fit();
        Cache::put($this->key(), ['since' => $this->since, 'fitted' => $fitted], now()->addHours(self::REFIT_AFTER_HOURS));

        return $fitted;
    }

    private function key(): string
    {
        return "station-correction:{$this->sensorId}";
    }
}

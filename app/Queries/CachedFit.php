<?php

declare(strict_types=1);

namespace App\Queries;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;

/**
 * One sensor's fit from the forecast service, cached and refitted when what it was fitted for changes or the service answers 409.
 * One key per sensor with what it was fitted for inside the value: the database store only deletes expired rows it reads.
 *
 * @template TFitted of array
 *
 * @phpstan-import-type Fitted from ForecastService
 * @phpstan-import-type LightFitted from ForecastService
 */
final readonly class CachedFit
{
    private const int STATION_REFIT_AFTER_HOURS = 24;

    /** Bounds a light fit left behind by a sensor that went silent. */
    private const int LIGHT_KEPT_DAYS = 2;

    /**
     * @param  array<string, int|string|null>  $fittedFor
     * @param  Closure(): TFitted  $fit
     */
    private function __construct(private string $key, private array $fittedFor, private CarbonInterface $expires, private Closure $fit) {}

    /**
     * @param  Closure(): Fitted  $fit
     * @return self<Fitted>
     */
    public static function stationCorrection(int $sensorId, ?int $since, Closure $fit): self
    {
        return new self("station-correction:{$sensorId}", ['since' => $since], now()->addHours(self::STATION_REFIT_AFTER_HOURS), $fit);
    }

    /**
     * Per local day of the newest reading: the light profile belongs to that day.
     *
     * @param  string  $day  Y-m-d
     * @param  Closure(): LightFitted  $fit
     * @return self<LightFitted>
     */
    public static function lightCorrection(int $sensorId, ?int $since, string $day, Closure $fit): self
    {
        return new self("light-correction:{$sensorId}", ['since' => $since, 'day' => $day], now()->addDays(self::LIGHT_KEPT_DAYS), $fit);
    }

    /**
     * A fit the service rejects as stale (409) is refitted once.
     *
     * @template TIssued
     *
     * @param  Closure(TFitted): TIssued  $issue
     * @return TIssued
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function issue(Closure $issue): mixed
    {
        try {
            return $issue($this->current());
        } catch (RequestException $exception) {
            if (! $exception->response->conflict()) {
                throw $exception;
            }

            return $issue($this->refit());
        }
    }

    /**
     * @return TFitted
     */
    public function current(): array
    {
        /** @var array{for?: array<string, int|string|null>, fitted: TFitted}|null $cached */
        $cached = Cache::get($this->key);

        if ($cached !== null && ($cached['for'] ?? null) === $this->fittedFor) {
            return $cached['fitted'];
        }

        return $this->refit();
    }

    /**
     * @return TFitted
     */
    private function refit(): array
    {
        $fitted = ($this->fit)();
        Cache::put($this->key, ['for' => $this->fittedFor, 'fitted' => $fitted], $this->expires);

        return $fitted;
    }
}

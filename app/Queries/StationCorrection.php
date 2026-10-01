<?php

declare(strict_types=1);

namespace App\Queries;

use App\ValueObject\LocalTime;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * One sensor's station correction, fitted by the forecast service on history_days and cached for a day.
 *
 * @phpstan-import-type Fitted from ForecastService
 */
final readonly class StationCorrection
{
    private const int REFIT_AFTER_HOURS = 24;

    public function __construct(private int $sensorId, private ForecastService $service) {}

    /**
     * @return Fitted
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function current(): array
    {
        /** @var Fitted */
        return Cache::remember($this->key(), now()->addHours(self::REFIT_AFTER_HOURS), fn (): array => $this->fit());
    }

    /**
     * @return Fitted
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function refit(): array
    {
        $fitted = $this->fit();
        Cache::put($this->key(), $fitted, now()->addHours(self::REFIT_AFTER_HOURS));

        return $fitted;
    }

    /**
     * @return Fitted
     */
    private function fit(): array
    {
        return $this->service->correction(array_filter([
            'longitude' => config('forecast.longitude'),
            'readings' => new ServiceReadings($this->sensorId)->recent((int) config('forecast.history_days') * 86400),
            'since' => $this->since(),
        ], fn (mixed $value): bool => $value !== null));
    }

    private function key(): string
    {
        return "station-correction:{$this->sensorId}";
    }

    /** Local midnight of history_since; an unparsable date is reported and ignored. */
    private function since(): ?int
    {
        $since = config('forecast.history_since');

        if (! is_string($since) || $since === '') {
            return null;
        }

        $midnight = DateTimeImmutable::createFromFormat('!Y-m-d', $since, new DateTimeZone(LocalTime::TIMEZONE));

        // createFromFormat() rolls 2026-17-09 over into 2027; require a round trip.
        if ($midnight === false || $midnight->format('Y-m-d') !== $since) {
            report(new InvalidArgumentException("FORECAST_HISTORY_SINCE is not a Y-m-d date: {$since}"));

            return null;
        }

        return $midnight->getTimestamp();
    }
}

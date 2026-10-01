<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ProtocolVersion;
use App\Models\Forecast;
use App\Models\Measurement;
use App\Models\Sensor;
use App\Queries\ChmiRecentDay;
use App\Queries\ForecastService;
use App\ValueObject\MeasurementDataV1;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;

/**
 * Forecasts one UTC day of the ČHMÚ reference station (forecast.reference) with
 * the base model only, so it can be scored beside the balcony. Re-running a day
 * deletes and rewrites its readings and forecasts, since ČHMÚ revises or drops
 * values. The rain gauge goes to the service but is not stored. Returns how many
 * forecasts were stored; an unpublished day gives none.
 *
 * @phpstan-import-type Reading from ChmiRecentDay
 */
class ForecastReferenceDay
{
    use Dispatchable;

    /** Days fetched before the forecast day: the models need 48 hours of history. */
    private const int LOOKBACK_DAYS = 2;

    public function __construct(public CarbonImmutable $day) {}

    public function handle(): int
    {
        $day = $this->day->utc()->startOfDay();
        $source = new ChmiRecentDay((string) config('forecast.reference.url'), (string) config('forecast.reference.wsi'));
        /** @var array<int, list<Reading>> $published readings by the day's first epoch */
        $published = [];

        for ($back = self::LOOKBACK_DAYS; $back >= 0; $back--) {
            $from = $day->subDays($back);
            $published[$from->getTimestamp()] = $source->readings($from);
        }

        $readings = array_merge(...array_values($published));

        $since = $day->getTimestamp();
        $until = $day->addDay()->getTimestamp();

        if (array_filter($readings, fn (array $reading): bool => $reading['timestamp'] >= $since && $reading['timestamp'] < $until) === []) {
            return 0;
        }

        $sensor = Sensor::reference();
        $answer = ForecastService::fromConfig()->base([
            'longitude' => config('forecast.reference.longitude'),
            'since' => $since,
            'readings' => $readings,
            'full' => true,
        ]);

        $forecasts = array_values(array_filter($answer['forecasts'], fn (array $forecast): bool => $forecast['issued_at'] < $until));

        DB::transaction(function () use ($sensor, $published, $forecasts, $answer, $since, $until): void {
            foreach ($published as $from => $dayReadings) {
                if ($dayReadings !== []) {
                    $this->replaceReadings($sensor, $from, $dayReadings);
                }
            }

            Forecast::query()->where('sensor_id', $sensor->id)->whereBetween('issued_at', [$since, $until - 1])->delete();

            foreach ($forecasts as $forecast) {
                Forecast::query()->create([
                    'sensor_id' => $sensor->id,
                    'issued_at' => $forecast['issued_at'],
                    'model' => $answer['model'],
                    'corrected' => false,
                    'correction' => null,
                    'data' => $forecast['horizons'],
                ]);
            }
        });

        return count($forecasts);
    }

    /**
     * Stored as protocol V1: hundredths of °C and %, pascals.
     *
     * @param  non-empty-list<Reading>  $readings
     */
    private function replaceReadings(Sensor $sensor, int $from, array $readings): void
    {
        Measurement::query()->where('sensor_id', $sensor->id)->whereBetween('timestamp', [$from, $from + 86_399])->delete();

        Measurement::query()->insert(
            array_map(fn (array $reading): array => [
                'sensor_id' => $sensor->id,
                'protocol_version' => ProtocolVersion::V1->value,
                'timestamp' => $reading['timestamp'],
                'data' => (string) new MeasurementDataV1(
                    temperature: (int) round($reading['temperature'] * 100),
                    humidity: (int) round($reading['humidity'] * 100),
                    pressure: (int) round($reading['pressure'] * 100),
                ),
                'created_at' => now(),
                'updated_at' => now(),
            ], $readings),
        );
    }
}

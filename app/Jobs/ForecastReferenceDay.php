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
 * Forecasts one UTC day of the ČHMÚ reference station (config
 * forecast.reference) the way the balcony is forecast, so the two can be
 * scored side by side: every 10-minute window of the day gets the six hours
 * ahead, and stored with the station's readings the accuracy pairs them
 * like the balcony's. The base models look 48 hours back, so the two days
 * before are fetched too; the service's /base answers the model alone - the
 * reference has no station correction to learn.
 *
 * A day run again replaces itself, removals included: ČHMÚ revises unchecked
 * values and may drop one, so each published day's readings and the day's
 * forecasts are deleted and written anew rather than upserted over. A day
 * ČHMÚ no longer publishes keeps what was stored. The rain
 * gauge goes to the service but is not stored: the protocol has no field for
 * it. Answers how many forecasts it stored; a day not published yet gives none.
 *
 * @phpstan-import-type Reading from ChmiRecentDay
 */
class ForecastReferenceDay
{
    use Dispatchable;

    /** Days before the forecast one, for the models' 48 hours of history. */
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
     * The day's readings as published now, in protocol V1 as a station would
     * send them: hundredths of °C and %, pascals.
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

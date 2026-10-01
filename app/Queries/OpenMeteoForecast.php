<?php

declare(strict_types=1);

namespace App\Queries;

use App\ValueObject\ChartWindow;
use App\ValueObject\StationSite;
use Illuminate\Support\Facades\Http;

/**
 * Numerical weather model temperature at the station from Open-Meteo, the
 * second baseline the forecast is scored against.
 */
final readonly class OpenMeteoForecast
{
    public function __construct(private string $url, private string $model) {}

    /** Null when FORECAST_NWP_URL is empty. */
    public static function fromConfig(): ?self
    {
        $url = config('forecast.nwp.url');

        return is_string($url) && $url !== '' ? new self($url, (string) config('forecast.nwp.model')) : null;
    }

    /**
     * °C at the middle of the ten-minute window each horizon is scored on,
     * interpolated between the model's hourly values; a horizon it does not
     * cover is left out.
     *
     * @param  non-empty-list<int>  $hours
     * @return array<int, float> by hours ahead
     */
    public function temperatures(int $issuedAt, array $hours, int $timeout = 10): array
    {
        $response = Http::timeout($timeout)->get($this->url, [
            'latitude' => StationSite::LATITUDE,
            'longitude' => StationSite::LONGITUDE,
            'elevation' => StationSite::ALTITUDE_METRES,
            'hourly' => 'temperature_2m',
            'models' => $this->model,
            // Counted from the current hour; the last window ends past max + 1.
            'forecast_hours' => max($hours) + 2,
            'timeformat' => 'unixtime',
            'timezone' => 'GMT',
        ])->throw();

        /** @var list<int> $times */
        $times = $response->json('hourly.time', []);
        /** @var list<float|int|null> $values */
        $values = $response->json('hourly.temperature_2m', []);
        $temperatures = [];

        foreach ($hours as $ahead) {
            $temperature = $this->interpolate($times, $values, $issuedAt + $ahead * 3600 + intdiv(ChartWindow::STEP_SECONDS, 2));

            if ($temperature !== null) {
                $temperatures[$ahead] = round($temperature, 2);
            }
        }

        return $temperatures;
    }

    /**
     * @param  list<int>  $times
     * @param  list<float|int|null>  $values
     */
    private function interpolate(array $times, array $values, int $at): ?float
    {
        for ($i = 0; $i < count($times) - 1; $i++) {
            if ($at < $times[$i] || $at > $times[$i + 1]) {
                continue;
            }

            if (! is_numeric($values[$i] ?? null) || ! is_numeric($values[$i + 1] ?? null)) {
                return null;
            }

            $share = ($at - $times[$i]) / ($times[$i + 1] - $times[$i]);

            return (float) $values[$i] + $share * ((float) $values[$i + 1] - (float) $values[$i]);
        }

        return null;
    }
}

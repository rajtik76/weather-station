<?php

declare(strict_types=1);

namespace App\Queries;

use DateTimeInterface;
use Illuminate\Support\Facades\Http;

/**
 * One UTC day of a ČHMÚ station's 10-minute record from the open data's
 * `recent` series (CC BY 4.0, ČHMÚ), in the forecast service's units: °C, %,
 * hPa at station level and mm of rain in the ten minutes. A value of a
 * quality the models were not trained on is left out, as fetch_chmi.py does
 * (0 good, 3 estimated, 5 unknown are kept), and a window without all three
 * of temperature, humidity and pressure is left out with it. A day not
 * published yet, or no longer kept, is the empty list.
 *
 * @phpstan-type Reading array{timestamp: int, temperature: float, humidity: float, pressure: float, rain: ?float}
 */
final readonly class ChmiRecentDay
{
    private const array GOOD_QUALITY = [0.0, 3.0, 5.0];

    /** ČHMÚ element code by the service's field. */
    private const array ELEMENTS = ['T' => 'temperature', 'H' => 'humidity', 'P' => 'pressure', 'SRA10M' => 'rain'];

    public function __construct(private string $baseUrl, private string $wsi) {}

    /**
     * @return list<Reading> oldest first
     */
    public function readings(DateTimeInterface $day): array
    {
        $response = Http::timeout(60)->get(rtrim($this->baseUrl, '/')."/10m-{$this->wsi}-{$day->format('Ymd')}.json");

        if ($response->notFound()) {
            return [];
        }

        /** @var list<array{0: string, 1: string, 2: string, 3: int|float|string|null, 4: string, 5: int|float|null}> $values */
        $values = $response->throw()->json('data.data.values', []);
        $windows = [];

        foreach ($values as [, $element, $at, $value, , $quality]) {
            $field = self::ELEMENTS[$element] ?? null;

            // A missing quality is not a good one: fetch_chmi.py drops it too.
            if ($field === null || ! is_numeric($value) || ! is_numeric($quality) || ! in_array((float) $quality, self::GOOD_QUALITY, true)) {
                continue;
            }

            $windows[(int) strtotime($at)][$field] = (float) $value;
        }

        ksort($windows);
        $readings = [];

        foreach ($windows as $timestamp => $window) {
            if (! isset($window['temperature'], $window['humidity'], $window['pressure'])) {
                continue;
            }

            $readings[] = [
                'timestamp' => $timestamp,
                'temperature' => $window['temperature'],
                'humidity' => $window['humidity'],
                'pressure' => $window['pressure'],
                'rain' => $window['rain'] ?? null,
            ];
        }

        return $readings;
    }
}

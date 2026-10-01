<?php

declare(strict_types=1);

use App\ValueObject\StationSite;

return [
    // Forecast service, e.g. http://weather-forecast:8000. Unset: no forecasts.
    'url' => env('FORECAST_URL'),

    // Days of history sent per request; the station correction learns from it.
    'history_days' => 60,

    // Local date, e.g. 2026-09-17: the station correction learns only from then on
    // (the radiation shield went up on 16.9.). Base models still get all history_days.
    'history_since' => env('FORECAST_HISTORY_SINCE'),

    // Models use local solar time: longitude only.
    'longitude' => StationSite::LONGITUDE,

    // ČHMÚ reference station: stored as a sensor of its own, kept out of the sensor picker.
    'reference' => [
        'name' => 'Plzeň-Mikulka (ČHMÚ)',
        'wsi' => '0-20000-0-11450',
        'longitude' => 13.378889,
        // One JSON file per station and UTC day; about a month back.
        'url' => 'https://opendata.chmi.cz/meteorology/climate/recent/data/10min',
    ],
];

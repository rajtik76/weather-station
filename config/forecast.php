<?php

declare(strict_types=1);

use App\ValueObject\StationSite;

return [
    // Forecast service, e.g. http://weather-forecast:8000. Unset: no forecasts.
    'url' => env('FORECAST_URL'),

    // Days of history the station correction is fitted on, once a day.
    'history_days' => 60,

    // Hours of readings sent with each forecast; the service needs 54.
    'lookback_hours' => 56,

    // Local date, e.g. 2026-09-17: the station correction learns only from then on
    // (the radiation shield went up on 16.9.).
    'history_since' => env('FORECAST_HISTORY_SINCE'),

    // Models use local solar time: longitude only.
    'longitude' => StationSite::LONGITUDE,

    // Numerical weather model temperature stored with every forecast. Empty URL: none.
    'nwp' => [
        'url' => env('FORECAST_NWP_URL', 'https://api.open-meteo.com/v1/forecast'),
        // DWD ICON: D2 (2 km) for the first two days, then EU and global.
        'model' => 'icon_seamless',
    ],
];

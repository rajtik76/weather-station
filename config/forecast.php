<?php

declare(strict_types=1);

return [
    // The forecast service on the internal Docker network, e.g.
    // http://weather-forecast:8000. Unset means no forecasts are made.
    'url' => env('FORECAST_URL'),

    // History sent with every request; the service learns the station
    // correction from it, so longer means a better fit up to a season.
    'history_days' => 60,

    // Optional local date, e.g. 2026-09-17: the station correction learns
    // only from readings from then on, for when the station changed enough
    // that older ones would teach it the wrong thing (the radiation shield
    // went up on 16.9.). The base models still get all history_days. Unset,
    // the correction learns from them all.
    'history_since' => env('FORECAST_HISTORY_SINCE'),

    // Plzeň-Slovany. The models use local solar time, so only the longitude matters.
    'longitude' => 13.40,
];

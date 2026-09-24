<?php

declare(strict_types=1);

return [
    // The forecast service on the internal Docker network, e.g.
    // http://weather-forecast:8000. Unset means no forecasts are made.
    'url' => env('FORECAST_URL'),

    // History sent with every request; the service learns the station
    // correction from it, so longer means a better fit up to a season.
    'history_days' => 60,

    // Plzeň-Slovany. The models use local solar time, so only the longitude matters.
    'longitude' => 13.40,
];

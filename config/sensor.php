<?php

declare(strict_types=1);

return [
    'api_token' => env('SENSOR_API_TOKEN'),

    // Push monitor URL, requested once a batch is stored. Unset means no ping.
    'heartbeat_url' => env('SENSOR_HEARTBEAT_URL'),
];

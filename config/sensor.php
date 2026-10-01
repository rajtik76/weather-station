<?php

declare(strict_types=1);

return [
    'api_token' => env('SENSOR_API_TOKEN'),

    // Push monitor URL, pinged once per stored batch. Unset: no ping.
    'heartbeat_url' => env('SENSOR_HEARTBEAT_URL'),
];

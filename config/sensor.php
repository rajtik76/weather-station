<?php

declare(strict_types=1);

return [
    'api_token' => env('SENSOR_API_TOKEN'),

    /*
     * Uptime Kuma push URL, pinged once a batch is stored. Unset means no
     * ping, which is what local and test runs want.
     */
    'heartbeat_url' => env('SENSOR_HEARTBEAT_URL'),
];

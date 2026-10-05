<?php

use App\Jobs\BackfillForecastBase;
use App\Jobs\BackfillLightExperiment;
use App\Models\Sensor;
use App\ValueObject\LocalTime;
use Illuminate\Support\Facades\Artisan;

Artisan::command('forecast:backfill-base', function (): void {
    foreach (Sensor::query()->get() as $sensor) {
        $filled = dispatch_sync(new BackfillForecastBase($sensor));
        $this->info("{$sensor->name}: {$filled} forecasts given their base");
    }
})->purpose('Fill in the uncorrected forecast on forecasts stored before the service returned it');

Artisan::command('forecast:backfill-light {from : Local date (Y-m-d) of the first forecast to fill}', function (string $from): int {
    $midnight = LocalTime::midnightOf($from);

    if (! $midnight instanceof LocalTime) {
        $this->error("Not a Y-m-d date: {$from}");

        return 1;
    }

    foreach (Sensor::query()->get() as $sensor) {
        $filled = dispatch_sync(new BackfillLightExperiment($sensor, $midnight->timestamp));
        $this->info("{$sensor->name}: {$filled} forecasts given the light experiment");
    }

    return 0;
})->purpose('Replay the light experiment on forecasts stored without its current version, as it would have been issued');

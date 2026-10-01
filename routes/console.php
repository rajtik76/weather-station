<?php

use App\Jobs\BackfillForecastBase;
use App\Models\Sensor;
use Illuminate\Support\Facades\Artisan;

Artisan::command('forecast:backfill-base', function (): void {
    foreach (Sensor::query()->get() as $sensor) {
        $filled = dispatch_sync(new BackfillForecastBase($sensor));
        $this->info("{$sensor->name}: {$filled} forecasts given their base");
    }
})->purpose('Fill in the uncorrected forecast on forecasts stored before the service returned it');

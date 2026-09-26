<?php

use App\Jobs\BackfillForecastBase;
use App\Models\Sensor;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('forecast:backfill-base', function (): void {
    foreach (Sensor::query()->get() as $sensor) {
        $filled = dispatch_sync(new BackfillForecastBase($sensor));
        $this->info("{$sensor->name}: {$filled} forecasts given their base");
    }
})->purpose('Fill in the uncorrected forecast on forecasts stored before the service returned it');

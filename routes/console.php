<?php

use App\Jobs\BackfillForecastBase;
use App\Jobs\ForecastReferenceDay;
use App\Models\Sensor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('forecast:backfill-base', function (): void {
    foreach (Sensor::query()->stations()->get() as $sensor) {
        $filled = dispatch_sync(new BackfillForecastBase($sensor));
        $this->info("{$sensor->name}: {$filled} forecasts given their base");
    }
})->purpose('Fill in the uncorrected forecast on forecasts stored before the service returned it');

Artisan::command('forecast:reference {--days=1 : UTC days to forecast, ending yesterday}', function (): void {
    if (blank(config('forecast.url'))) {
        $this->warn('FORECAST_URL is not set: there is no service to forecast with.');

        return;
    }

    $yesterday = CarbonImmutable::now('UTC')->subDay()->startOfDay();

    for ($back = max(1, (int) $this->option('days')) - 1; $back >= 0; $back--) {
        $day = $yesterday->subDays($back);
        $stored = dispatch_sync(new ForecastReferenceDay($day));
        $this->info("{$day->format('Y-m-d')}: {$stored} forecasts");
    }
})->purpose('Forecast the ČHMÚ reference station over the last days of its published record');

// ČHMÚ may revise a day after publishing it: redo the day before too.
Schedule::command('forecast:reference --days=2')->dailyAt('01:00');

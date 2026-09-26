<?php

declare(strict_types=1);

use App\Models\Forecast;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

const CORRECTION_MIGRATION = 'database/migrations/2026_09_26_154152_add_correction_to_forecasts_table.php';

it('dates every forecast stored before the column to the first version of the correction', function (): void {
    Artisan::call('migrate:rollback', ['--path' => CORRECTION_MIGRATION]);
    $forecast = Forecast::factory()->make(['correction' => null]);
    // Written in the shape the table had then, without the column.
    DB::table('forecasts')->insert([
        'sensor_id' => $forecast->sensor_id,
        'issued_at' => $forecast->issued_at,
        'model' => $forecast->model,
        'corrected' => $forecast->corrected,
        'data' => json_encode($forecast->data, JSON_THROW_ON_ERROR),
    ]);

    Artisan::call('migrate', ['--path' => CORRECTION_MIGRATION]);

    expect(Forecast::query()->sole()->correction)->toBe(1);
});

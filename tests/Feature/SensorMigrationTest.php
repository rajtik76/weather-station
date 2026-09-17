<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The record as it stood before sensors were a table: names on the rows,
 * events belonging to nothing. Migrations after the two that created those
 * tables are rolled back so the data can be written in the old shape.
 */
function schemaBeforeSensors(): void
{
    Artisan::call('migrate:reset');
    Artisan::call('migrate', [
        '--path' => [
            'database/migrations/2026_09_02_052458_create_measurements_table.php',
            'database/migrations/2026_09_12_133037_create_station_events_table.php',
        ],
    ]);
}

/**
 * @param  list<string>  $names
 */
function oldMeasurements(array $names): void
{
    foreach ($names as $index => $name) {
        DB::table('measurements')->insert([
            'sensor_name' => $name,
            'timestamp' => 1757000000 + $index,
            'protocol_version' => 1,
            'data' => '{"temperature":2602,"humidity":4871,"pressure":97389}',
        ]);
    }
}

it('derives the sensors from the names on the existing readings', function (): void {
    schemaBeforeSensors();
    oldMeasurements(['bme280-north', 'bme280-north', 'bme280-south']);

    Artisan::call('migrate');

    expect(DB::table('sensors')->orderBy('name')->pluck('name')->all())->toBe(['bme280-north', 'bme280-south'])
        ->and(Schema::hasColumn('measurements', 'sensor_name'))->toBeFalse();

    $north = DB::table('sensors')->where('name', 'bme280-north')->value('id');
    $south = DB::table('sensors')->where('name', 'bme280-south')->value('id');

    expect(DB::table('measurements')->orderBy('timestamp')->pluck('sensor_id')->all())->toBe([$north, $north, $south]);
});

it('slugs the existing names for the URL, keeping them apart', function (): void {
    schemaBeforeSensors();
    oldMeasurements(['Sensor 1', 'sensor-1', 'Balkón #2']);

    Artisan::call('migrate');

    expect(DB::table('sensors')->orderBy('name')->pluck('slug', 'name')->all())->toBe([
        'Balkón #2' => 'balkon-2',
        'Sensor 1' => 'sensor-1',
        'sensor-1' => 'sensor-1-2',
    ]);
});

it('attaches the existing events to the only sensor', function (): void {
    schemaBeforeSensors();
    oldMeasurements(['bme280-north']);
    DB::table('station_events')->insert(['occurred_at' => now(), 'title' => 'Radiation shield fitted']);

    Artisan::call('migrate');

    expect(DB::table('station_events')->sole()->sensor_id)
        ->toBe(DB::table('sensors')->sole()->id);
});

it('refuses to guess which sensor an event belongs to, and finishes once told', function (): void {
    schemaBeforeSensors();
    oldMeasurements(['bme280-north', 'bme280-south']);
    DB::table('station_events')->insert(['occurred_at' => now(), 'title' => 'Radiation shield fitted']);

    expect(fn () => Artisan::call('migrate'))
        ->toThrow(RuntimeException::class, 'set station_events.sensor_id by hand');

    // The column is left behind for exactly this, and the rerun picks up
    // where it stopped rather than tripping over its own column.
    $south = DB::table('sensors')->where('name', 'bme280-south')->value('id');
    DB::table('station_events')->update(['sensor_id' => $south]);

    Artisan::call('migrate');

    expect(DB::table('station_events')->sole()->sensor_id)->toBe($south)
        ->and(DB::table('migrations')->where('migration', 'like', '%attach_station_events_to_sensors')->exists())->toBeTrue();
});

it('puts the names back on the readings when rolled back', function (): void {
    schemaBeforeSensors();
    oldMeasurements(['bme280-north', 'bme280-south']);
    Artisan::call('migrate');

    // Everything the migrate above ran is one batch, so this rolls back the
    // sensor migrations whatever has been added since.
    Artisan::call('migrate:rollback');

    expect(Schema::hasTable('sensors'))->toBeFalse()
        ->and(DB::table('measurements')->orderBy('timestamp')->pluck('sensor_name')->all())
        ->toBe(['bme280-north', 'bme280-south']);
});

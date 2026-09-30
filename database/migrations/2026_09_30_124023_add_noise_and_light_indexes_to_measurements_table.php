<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Partial: only the rows that carry the key, so "has this sensor ever sent it"
        // is a lookup instead of a scan of the jsonb over the sensor's whole history.
        DB::statement("CREATE INDEX measurements_sensor_id_noise_index ON measurements (sensor_id) WHERE data->'noise' IS NOT NULL");
        DB::statement("CREATE INDEX measurements_sensor_id_illuminance_index ON measurements (sensor_id) WHERE data->'illuminance' IS NOT NULL");

        // Without statistics on the expressions the planner guesses nearly every row
        // carries the key and picks a sequential scan over the indexes.
        DB::statement("CREATE STATISTICS measurements_noise_stats ON (data->'noise') FROM measurements");
        DB::statement("CREATE STATISTICS measurements_illuminance_stats ON (data->'illuminance') FROM measurements");
        DB::statement('ANALYZE measurements');
    }

    public function down(): void
    {
        DB::statement('DROP STATISTICS measurements_illuminance_stats');
        DB::statement('DROP STATISTICS measurements_noise_stats');
        DB::statement('DROP INDEX measurements_sensor_id_illuminance_index');
        DB::statement('DROP INDEX measurements_sensor_id_noise_index');
    }
};

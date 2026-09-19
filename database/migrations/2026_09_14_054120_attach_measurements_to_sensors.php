<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('measurements', function (Blueprint $table): void {
            $table->foreignId('sensor_id')->nullable()->after('id')->constrained()->restrictOnDelete();
        });

        // sensors was seeded from these names, so every row matches.
        DB::table('measurements')->update([
            'sensor_id' => DB::raw('(SELECT id FROM sensors WHERE sensors.name = measurements.sensor_name)'),
        ]);

        Schema::table('measurements', function (Blueprint $table): void {
            $table->foreignId('sensor_id')->nullable(false)->change();
        });

        Schema::table('measurements', function (Blueprint $table): void {
            $table->dropUnique(['sensor_name', 'timestamp']);
            $table->unique(['sensor_id', 'timestamp']);
            $table->dropColumn('sensor_name');
        });
    }

    public function down(): void
    {
        Schema::table('measurements', function (Blueprint $table): void {
            $table->string('sensor_name', 50)->nullable()->after('id');
        });

        DB::table('measurements')->update([
            'sensor_name' => DB::raw('(SELECT name FROM sensors WHERE sensors.id = measurements.sensor_id)'),
        ]);

        Schema::table('measurements', function (Blueprint $table): void {
            $table->string('sensor_name', 50)->nullable(false)->change();
        });

        Schema::table('measurements', function (Blueprint $table): void {
            $table->dropUnique(['sensor_id', 'timestamp']);
            $table->unique(['sensor_name', 'timestamp']);
            $table->dropConstrainedForeignId('sensor_id');
        });
    }
};

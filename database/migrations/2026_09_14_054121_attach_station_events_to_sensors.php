<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Not wrapped in a transaction on purpose: when this stops to ask for
     * events to be assigned by hand, the nullable column must stay behind for
     * the operator to fill in. Postgres would otherwise roll it back with the
     * exception, and SQLite never rolls DDL back at all - so the rerun path
     * is the same on both.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        if (! Schema::hasColumn('station_events', 'sensor_id')) {
            Schema::table('station_events', function (Blueprint $table): void {
                $table->foreignId('sensor_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            });
        }

        $this->assignExistingEvents();

        Schema::table('station_events', function (Blueprint $table): void {
            $table->foreignId('sensor_id')->nullable(false)->change();
            $table->index(['sensor_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::table('station_events', function (Blueprint $table): void {
            $table->dropIndex(['sensor_id', 'occurred_at']);
            $table->dropConstrainedForeignId('sensor_id');
        });
    }

    /**
     * Events written before they belonged to a sensor.
     *
     * An event carries nothing that names its sensor, so the only record it
     * can be attached to unambiguously is one where a single sensor exists.
     * With several, guessing would pin an event to the wrong chart, so the
     * migration stops and asks for the assignment to be made by hand; on the
     * rerun only rows still without a sensor count.
     */
    private function assignExistingEvents(): void
    {
        $unassigned = DB::table('station_events')->whereNull('sensor_id');

        if (! $unassigned->exists()) {
            return;
        }

        $sensors = DB::table('sensors')->pluck('id');

        if ($sensors->count() !== 1) {
            throw new RuntimeException(
                'station_events holds rows without a sensor and there is not exactly one sensor to attach them to; set station_events.sensor_id by hand and rerun.'
            );
        }

        $unassigned->update(['sensor_id' => $sensors->sole()]);
    }
};

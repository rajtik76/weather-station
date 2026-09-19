<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * No transaction on purpose: when this stops to ask for events to be
     * assigned by hand, the nullable column has to stay behind to fill in.
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
     * Events written before they belonged to a sensor. Unambiguous only
     * with a single sensor; with several the migration stops and asks for
     * the assignment by hand, and the rerun counts only unassigned rows.
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

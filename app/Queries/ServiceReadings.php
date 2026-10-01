<?php

declare(strict_types=1);

namespace App\Queries;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Readings in the forecast service's units (°C, %, hPa); reads protocol keys directly, so a renamed field must change this too. */
final readonly class ServiceReadings
{
    public function __construct(private int $sensorId) {}

    /**
     * Oldest first, both ends included; no `$until` reads through the newest, even one stamped ahead.
     *
     * @return list<array{timestamp: int, temperature: float, humidity: float, pressure: float}>
     */
    public function between(int $from, ?int $until = null): array
    {
        /** @var list<object{timestamp: int, temperature: int, humidity: int, pressure: int}> $rows */
        $rows = DB::table('measurements')
            ->where('sensor_id', $this->sensorId)
            ->where('timestamp', '>=', $from)
            ->when($until !== null, fn (Builder $query): Builder => $query->where('timestamp', '<=', $until))
            ->orderBy('timestamp')
            ->select('timestamp')
            ->selectRaw("(data->>'temperature')::int AS temperature")
            ->selectRaw("(data->>'humidity')::int AS humidity")
            ->selectRaw("(data->>'pressure')::int AS pressure")
            ->get()
            ->all();

        return array_map(fn (object $row): array => [
            'timestamp' => (int) $row->timestamp,
            'temperature' => $row->temperature / 100,
            'humidity' => $row->humidity / 100,
            'pressure' => $row->pressure / 100,
        ], $rows);
    }
}

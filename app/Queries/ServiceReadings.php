<?php

declare(strict_types=1);

namespace App\Queries;

use App\ValueObject\ChartWindow;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Readings in the forecast service's units (°C, %, hPa, lx); reads protocol keys directly, so a renamed field must change this too.
 * Lit readings add the in-window temperature extremes (null when not reported).
 *
 * @phpstan-type Reading array{timestamp: int, temperature: float, humidity: float, pressure: float}
 * @phpstan-type LitReading array{timestamp: int, temperature: float, humidity: float, pressure: float, illuminance: ?float, received_at: int, temperature_min: ?float, temperature_max: ?float}
 */
final readonly class ServiceReadings
{
    /** Tolerated clock lead; a row stamped further ahead is a clock fault. */
    private const int AHEAD_SECONDS = 3 * ChartWindow::STEP_SECONDS;

    public function __construct(private int $sensorId) {}

    /**
     * The last $seconds up to now, plus readings stamped up to AHEAD_SECONDS ahead.
     *
     * @return ($withLight is true ? list<LitReading> : list<Reading>)
     */
    public function recent(int $seconds, bool $withLight = false): array
    {
        return $this->between(now()->getTimestamp() - $seconds, now()->getTimestamp() + self::AHEAD_SECONDS, $withLight);
    }

    /**
     * Lit readings stamped from $from on that the server held at $moment, as a job running then read them.
     *
     * @return list<LitReading>
     */
    public function arrivedBy(int $from, int $moment): array
    {
        return array_values(array_filter(
            $this->between($from, $moment + self::AHEAD_SECONDS, withLight: true),
            fn (array $reading): bool => $reading['received_at'] <= $moment,
        ));
    }

    /** When the server first held a reading stamped within $from..$until; null without one. */
    public function firstArrival(int $from, int $until): ?int
    {
        $arrived = DB::table('measurements')
            ->where('sensor_id', $this->sensorId)
            ->whereBetween('timestamp', [$from, $until])
            ->min('created_at');

        return $arrived === null ? null : Date::parse($arrived)->getTimestamp();
    }

    /**
     * Oldest first, both ends included; no `$until` reads through the newest, even one stamped ahead.
     *
     * @return ($withLight is true ? list<LitReading> : list<Reading>)
     */
    public function between(int $from, ?int $until = null, bool $withLight = false): array
    {
        /** @var list<object{timestamp: int, temperature: int, humidity: int, pressure: int, illuminance: ?int, received_at: int, temperature_min: ?int, temperature_max: ?int}> $rows */
        $rows = DB::table('measurements')
            ->where('sensor_id', $this->sensorId)
            ->where('timestamp', '>=', $from)
            ->when($until !== null, fn (Builder $query): Builder => $query->where('timestamp', '<=', $until))
            ->orderBy('timestamp')
            ->select('timestamp')
            ->selectRaw("(data->>'temperature')::int AS temperature")
            ->selectRaw("(data->>'humidity')::int AS humidity")
            ->selectRaw("(data->>'pressure')::int AS pressure")
            ->when($withLight, fn (Builder $query): Builder => $query
                ->selectRaw("(data->>'illuminance')::bigint AS illuminance")
                ->selectRaw("FLOOR(EXTRACT(EPOCH FROM created_at AT TIME ZONE 'UTC'))::bigint AS received_at")
                ->selectRaw("(data->>'temperature_min')::int AS temperature_min")
                ->selectRaw("(data->>'temperature_max')::int AS temperature_max"))
            ->get()
            ->all();

        return array_map(fn (object $row): array => [
            'timestamp' => (int) $row->timestamp,
            'temperature' => $row->temperature / 100,
            'humidity' => $row->humidity / 100,
            'pressure' => $row->pressure / 100,
            ...($withLight ? [
                'illuminance' => $row->illuminance === null ? null : $row->illuminance / 100,
                'received_at' => (int) $row->received_at,
                'temperature_min' => $row->temperature_min === null ? null : $row->temperature_min / 100,
                'temperature_max' => $row->temperature_max === null ? null : $row->temperature_max / 100,
            ] : []),
        ], $rows);
    }
}

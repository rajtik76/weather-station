<?php

declare(strict_types=1);

namespace App\Queries;

use App\ValueObject\LocalTime;
use App\ValueObject\ModelName;
use Illuminate\Support\Facades\DB;

/**
 * The forecast's own history for one sensor: every forecast where the model
 * or the station correction's version differs from the forecast before it,
 * newest first. A switch back after a rollback is a change too, so these are
 * transitions in order, not first uses. Read off the stored forecasts, so the
 * list is what actually ran here, not what was deployed. A forecast stored
 * before correction versions were kept (null) neither starts nor ends one.
 * PostgreSQL's window functions, like the rest of app/Queries.
 *
 * @phpstan-type Change array{at: int, date: string, model: ?string, correction: ?int}
 */
final readonly class ForecastChanges
{
    /** With no sensor the id is null and nothing matches. */
    public function __construct(private ?int $sensorId) {}

    /**
     * @return list<Change>
     */
    public function all(): array
    {
        $changes = [
            ...array_map(fn (object $row): array => [
                'at' => (int) $row->issued_at,
                'model' => ModelName::of((string) $row->value),
                'correction' => null,
            ], $this->transitions('model')),
            ...array_map(fn (object $row): array => [
                'at' => (int) $row->issued_at,
                'model' => null,
                'correction' => (int) $row->value,
            ], $this->transitions('correction')),
        ];

        usort($changes, fn (array $a, array $b): int => $b['at'] <=> $a['at']);

        return array_map(fn (array $change): array => [...$change, 'date' => LocalTime::of($change['at'])->date()], $changes);
    }

    /**
     * The forecasts whose $column differs from the one before, the first included.
     *
     * @param  'model'|'correction'  $column
     * @return list<object{issued_at: int|string, value: int|string}>
     */
    private function transitions(string $column): array
    {
        $ordered = DB::table('forecasts')
            ->where('sensor_id', $this->sensorId)
            ->whereNotNull($column)
            ->select('issued_at')
            ->selectRaw("{$column} AS value")
            ->selectRaw("LAG({$column}) OVER (ORDER BY issued_at) AS previous");

        /** @var list<object{issued_at: int|string, value: int|string}> */
        return DB::query()
            ->fromSub($ordered, 'ordered')
            ->whereRaw('previous IS DISTINCT FROM value')
            ->select('issued_at', 'value')
            ->get()
            ->all();
    }
}

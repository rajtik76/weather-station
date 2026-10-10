<?php

declare(strict_types=1);

namespace App\Queries;

use App\ValueObject\ChartWindow;
use Illuminate\Support\Facades\DB;

/** One sensor's temperature per ten-minute slot in °C; a slot stamped twice keeps its first reading, as the service's grid does. */
final readonly class SlotTemperatures
{
    private const int STEP = ChartWindow::STEP_SECONDS;

    /** Null id matches nothing. */
    public function __construct(private ?int $sensorId) {}

    /**
     * Slots from $from up to, not including, $until.
     *
     * @return array<int, float>
     */
    public function between(int $from, int $until): array
    {
        $rows = DB::query()
            ->fromSub(
                DB::table('measurements')
                    ->where('sensor_id', $this->sensorId)
                    ->where('timestamp', '>=', $from)
                    ->where('timestamp', '<', $until)
                    ->selectRaw('(timestamp / ?::int) * ?::int AS slot', [self::STEP, self::STEP])
                    ->addSelect('timestamp')
                    ->selectRaw("(data->>'temperature')::int / 100.0 AS temperature"),
                'window',
            )
            ->selectRaw('DISTINCT ON (slot) slot, temperature')
            ->orderBy('slot')
            ->orderBy('timestamp')
            ->get();

        $temperatures = [];

        foreach ($rows as $row) {
            $temperatures[(int) $row->slot] = (float) $row->temperature;
        }

        return $temperatures;
    }
}

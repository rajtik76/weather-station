<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Measurement;
use App\ValueObject\Readout;
use Illuminate\Database\Eloquent\Builder;

/**
 * One sensor's readings outside the charts, by the station's own stamp (a buffered batch arrives late).
 *
 * @phpstan-import-type DayRow from Readout
 */
final readonly class StationRecord
{
    /** Null id matches nothing. */
    public function __construct(private ?int $sensorId) {}

    public function newest(): ?Measurement
    {
        return $this->measurements()->orderByDesc('timestamp')->first();
    }

    /**
     * @return list<DayRow>
     */
    public function readoutsSince(int $from): array
    {
        return array_map(fn (Measurement $measurement): array => Readout::of($measurement->data)->toArray(), $this->since($from));
    }

    /**
     * @return list<array{at: int, t: float}>
     */
    public function temperaturesSince(int $from): array
    {
        return array_map(fn (Measurement $measurement): array => [
            'at' => $measurement->timestamp,
            't' => Readout::of($measurement->data)->temperature(),
        ], $this->since($from));
    }

    /**
     * @return list<Measurement>
     */
    private function since(int $from): array
    {
        return array_values($this->measurements()->where('timestamp', '>=', $from)->orderBy('timestamp')->get()->all());
    }

    /**
     * @return Builder<Measurement>
     */
    private function measurements(): Builder
    {
        return Measurement::query()->where('sensor_id', $this->sensorId);
    }
}

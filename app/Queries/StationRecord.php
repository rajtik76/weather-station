<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Measurement;
use App\ValueObject\Readout;
use Illuminate\Database\Eloquent\Builder;

/**
 * One sensor's stored readings as the pages read them outside the charts:
 * the newest one, and a stretch of them in display units. By the station's
 * own stamp throughout - a buffered batch arrives late and says nothing
 * about when the sensor was read.
 *
 * @phpstan-import-type DayRow from Readout
 */
final readonly class StationRecord
{
    /** With no sensor the id is null and nothing matches, which is the empty page. */
    public function __construct(private ?int $sensorId) {}

    public function newest(): ?Measurement
    {
        return $this->measurements()->orderByDesc('timestamp')->first();
    }

    /**
     * Every reading from $from on, oldest first, as the day readouts read them.
     *
     * @return list<DayRow>
     */
    public function readoutsSince(int $from): array
    {
        return array_map(fn (Measurement $measurement): array => Readout::of($measurement->data)->toArray(), $this->since($from));
    }

    /**
     * Every reading's temperature from $from on, oldest first, by its stamp.
     *
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

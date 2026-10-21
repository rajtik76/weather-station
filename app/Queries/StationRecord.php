<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Measurement;
use App\ValueObject\ChartWindow;
use App\ValueObject\Readout;
use Illuminate\Database\Eloquent\Builder;

/**
 * @phpstan-import-type DayRow from Readout
 */
final readonly class StationRecord
{
    public const int SILENT_AFTER_SECONDS = 3 * ChartWindow::STEP_SECONDS;

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
     * @return list<array{timestamp: int, rain: ?bool}>
     */
    public function rainSince(int $from): array
    {
        return array_map(
            fn (Measurement $measurement): array => ['timestamp' => $measurement->timestamp, 'rain' => Readout::of($measurement->data)->rain()],
            $this->since($from),
        );
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

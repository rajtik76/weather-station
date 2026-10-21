<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Measurement;
use App\Models\Sensor;
use App\Models\StationReport;
use App\Queries\LatestStationReport;
use App\Queries\StationRecord;
use App\Queries\Stations;
use App\ValueObject\BoardReport;
use App\ValueObject\LocalTime;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * @phpstan-import-type Report from BoardReport
 *
 * @property-read Collection<int, Sensor> $sensors
 * @property-read ?Sensor $selectedSensor
 * @property-read bool $hasSensorChoice
 * @property-read ?Measurement $newestMeasurement
 * @property-read bool $isSilent
 * @property-read ?string $measuredAt
 * @property-read ?string $measuredAgo
 * @property-read Report|null $stationReport
 * @property-read int $currentYear
 */
abstract class StationPage extends Component
{
    #[Url]
    public ?string $sensor = null;

    public function mount(): void
    {
        $this->normaliseSensor();
    }

    public function updatedSensor(): void
    {
        $this->normaliseSensor();
    }

    /**
     * @return Collection<int, Sensor>
     */
    #[Computed]
    public function sensors(): Collection
    {
        return new Stations()->all();
    }

    #[Computed]
    public function selectedSensor(): ?Sensor
    {
        return $this->sensors->firstWhere('slug', $this->sensor) ?? $this->sensors->first();
    }

    #[Computed]
    public function hasSensorChoice(): bool
    {
        return $this->sensors->count() >= 2;
    }

    /**
     * @return array{sensor?: string}
     */
    public function sensorQuery(): array
    {
        return $this->hasSensorChoice && $this->sensor !== null ? ['sensor' => $this->sensor] : [];
    }

    #[Computed]
    public function newestMeasurement(): ?Measurement
    {
        return $this->record()->newest();
    }

    #[Computed]
    public function isSilent(): bool
    {
        return $this->newestMeasurement === null
            || $this->newestMeasurement->timestamp < now()->getTimestamp() - StationRecord::SILENT_AFTER_SECONDS;
    }

    #[Computed]
    public function measuredAt(): ?string
    {
        return $this->newestMeasurement === null ? null : LocalTime::of($this->newestMeasurement->timestamp)->stamp();
    }

    #[Computed]
    public function measuredAgo(): ?string
    {
        return $this->newestMeasurement === null ? null : LocalTime::of($this->newestMeasurement->timestamp)->ago();
    }

    /**
     * @return Report|null
     */
    #[Computed]
    public function stationReport(): ?array
    {
        $report = new LatestStationReport($this->selectedSensor?->id)->find();

        return $report instanceof StationReport ? BoardReport::of($report)->toArray() : null;
    }

    #[Computed]
    public function currentYear(): int
    {
        return now(LocalTime::TIMEZONE)->year;
    }

    protected function record(): StationRecord
    {
        return new StationRecord($this->selectedSensor?->id);
    }

    private function normaliseSensor(): void
    {
        unset($this->selectedSensor);

        $this->sensor = $this->selectedSensor?->slug;
    }
}

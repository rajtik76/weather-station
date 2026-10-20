<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\TemperatureCalibration;
use App\Queries\ShieldCalibrations;
use App\Queries\TemperatureCalibrator;
use App\Queries\TemperatureHistory;
use App\ValueObject\CalibrationHistory;
use App\ValueObject\ComparisonDay;
use App\ValueObject\TemperatureComparison;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/**
 * @phpstan-import-type Summary from TemperatureComparison
 * @phpstan-import-type HistoryRow from CalibrationHistory
 *
 * @property-read ComparisonDay $day
 * @property-read ?TemperatureCalibration $dayCandidate
 * @property-read list<array{0: int, 1: ?float, 2: ?float, 3: ?float}> $comparisonRows
 * @property-read Summary $summary
 * @property-read list<HistoryRow> $modelHistory
 * @property-read ?TemperatureCalibration $latestCandidate
 */
#[Title('Balcony Station · Mikulka comparison')]
class Comparison extends StationPage
{
    #[Url]
    public ?string $date = null;

    public function mount(): void
    {
        parent::mount();
        $this->date = $this->day->date;
    }

    public function updatedDate(): void
    {
        unset($this->day, $this->dayCandidate, $this->comparisonRows);
        $this->date = $this->day->date;
    }

    public function moveDay(int $direction): void
    {
        $this->date = $this->day->shift($direction);
        unset($this->day, $this->dayCandidate, $this->comparisonRows);
    }

    #[Computed]
    public function day(): ComparisonDay
    {
        return ComparisonDay::of($this->date);
    }

    #[Computed]
    public function dayCandidate(): ?TemperatureCalibration
    {
        return resolve(ShieldCalibrations::class)->validatingWithin($this->selectedSensor?->id, $this->day->from, $this->day->until);
    }

    /** @return list<array{0: int, 1: ?float, 2: ?float, 3: ?float}> */
    #[Computed]
    public function comparisonRows(): array
    {
        return resolve(TemperatureHistory::class)->comparisonRows($this->selectedSensor?->id, $this->day->from, $this->day->axisUntil(now()->getTimestamp()), $this->dayCandidate);
    }

    /** @return Summary */
    #[Computed]
    public function summary(): array
    {
        return TemperatureComparison::summary(resolve(TemperatureHistory::class)->readings(
            $this->selectedSensor?->id,
            now()->subDays(ComparisonDay::HISTORY_DAYS)->getTimestamp(),
            now()->getTimestamp() + 1,
            withReference: true,
        ));
    }

    /** @return list<HistoryRow> */
    #[Computed]
    public function modelHistory(): array
    {
        $calibrations = resolve(ShieldCalibrations::class)->history($this->selectedSensor?->id);
        $provisional = [];

        foreach ($calibrations->filter(fn (TemperatureCalibration $calibration): bool => $calibration->isValidating()) as $calibration) {
            $provisional[$calibration->id] = Cache::remember('comparison:versus-current-so-far:'.$calibration->id, now()->addMinutes(10),
                fn (): array => resolve(TemperatureCalibrator::class)->versusCurrentSoFar($calibration, now()->getTimestamp()));
        }

        return CalibrationHistory::rows($calibrations, now()->getTimestamp(), $provisional);
    }

    #[Computed]
    public function latestCandidate(): ?TemperatureCalibration
    {
        return resolve(ShieldCalibrations::class)->newest($this->selectedSensor?->id);
    }

    public function render(): View
    {
        return view('livewire.comparison');
    }
}

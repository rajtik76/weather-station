<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Queries\TemperatureHistory;
use App\ValueObject\ComparisonDay;
use App\ValueObject\TemperatureComparison;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/**
 * @phpstan-import-type Summary from TemperatureComparison
 *
 * @property-read ComparisonDay $day
 * @property-read list<array{0: int, 1: ?float, 2: ?float, 3: ?float}> $comparisonRows
 * @property-read Summary $summary
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
        unset($this->day, $this->comparisonRows);
        $this->date = $this->day->date;
    }

    public function moveDay(int $direction): void
    {
        $this->date = $this->day->shift($direction);
        unset($this->day, $this->comparisonRows);
    }

    #[Computed]
    public function day(): ComparisonDay
    {
        return ComparisonDay::of($this->date);
    }

    /** @return list<array{0: int, 1: ?float, 2: ?float, 3: ?float}> */
    #[Computed]
    public function comparisonRows(): array
    {
        return resolve(TemperatureHistory::class)->comparisonRows($this->selectedSensor?->id, $this->day->from, $this->day->until);
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

    public function render(): View
    {
        return view('livewire.comparison');
    }
}

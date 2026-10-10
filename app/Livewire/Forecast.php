<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Queries\CachedModelRace;
use App\Queries\ForecastAccuracy;
use App\Queries\ForecastChanges;
use App\ValueObject\RaceStandings;
use App\ValueObject\Scoreboard;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;

/**
 * The forecast page; does not poll, so the reader's scores hold still.
 *
 * @phpstan-import-type Score from ForecastAccuracy
 * @phpstan-import-type Row from Scoreboard
 * @phpstan-import-type Change from ForecastChanges
 *
 * @property-read list<Row> $scoreboard
 * @property-read Score|null $score
 * @property-read list<Change> $changes
 * @property-read RaceStandings $race
 */
#[Title('Balcony Station Forecast')]
class Forecast extends StationPage
{
    /** Detail charts' horizon in hours; not #[Url], so a reload starts from an hour ahead. */
    public int $horizon = 1;

    /** Re-checked every render: the chosen horizon may not have come true yet. */
    public function render(): View
    {
        $shown = $this->score['hours'] ?? null;

        if ($shown !== null && $shown !== $this->horizon) {
            $this->horizon = $shown;
        }

        return view('livewire.forecast');
    }

    /**
     * @return list<Row>
     */
    #[Computed]
    public function scoreboard(): array
    {
        return Scoreboard::of($this->forecastAccuracy);
    }

    /**
     * The chosen horizon's score, else the longest scored; render() moves the choice onto it.
     *
     * @return Score|null
     */
    #[Computed]
    public function score(): ?array
    {
        $scores = $this->forecastAccuracy;

        return array_find($scores, fn (array $score): bool => $score['hours'] === $this->horizon)
            ?? ($scores === [] ? null : $scores[count($scores) - 1]);
    }

    /** The standings today's forecasts are picked by. */
    #[Computed]
    public function race(): RaceStandings
    {
        return new CachedModelRace($this->selectedSensor?->id)->standings();
    }

    /**
     * @return list<Change>
     */
    #[Computed]
    public function changes(): array
    {
        return new ForecastChanges($this->selectedSensor?->id)->all();
    }
}

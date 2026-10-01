<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Sensor;
use App\Queries\CachedForecastAccuracy;
use App\Queries\ForecastAccuracy;
use App\Queries\ForecastChanges;
use App\ValueObject\ReferenceSkill;
use App\ValueObject\Scoreboard;
use App\ValueObject\Verdict;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;

/**
 * The forecast page: how the model works, the forecast now, and how the last
 * month of forecasts scored, by horizon and in detail for one of them, beside
 * the same model on the ČHMÚ reference station (ForecastReferenceDay). It does
 * not poll - the scores move once per upload and the reader is studying them.
 *
 * `#[Computed]` methods are declared as properties for Larastan.
 *
 * @phpstan-import-type Score from ForecastAccuracy
 * @phpstan-import-type Row from Scoreboard
 * @phpstan-import-type Change from ForecastChanges
 *
 * @property-read list<Score> $referenceAccuracy
 * @property-read list<Row> $scoreboard
 * @property-read list<?float> $referenceSkillByDay
 * @property-read Score|null $score
 * @property-read list<Change> $changes
 */
#[Title('Balcony Station Forecast')]
class Forecast extends StationPage
{
    /**
     * The horizon the detail charts read, in hours. Livewire state, not #[Url],
     * so a reload starts from the verdict's own horizon.
     */
    public int $horizon = Verdict::HOURS;

    /** Every render: the chosen horizon may not have come true yet. */
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
        return Scoreboard::of($this->forecastAccuracy, ReferenceSkill::byHorizon($this->referenceAccuracy));
    }

    /**
     * The reference station's month, scored like the balcony's; empty until its job has run.
     *
     * @return list<Score>
     */
    #[Computed]
    public function referenceAccuracy(): array
    {
        $reference = Sensor::findReference();

        return $reference instanceof Sensor ? new CachedForecastAccuracy($reference->id)->lastDays(self::ACCURACY_DAYS) : [];
    }

    /**
     * The reference's skill on each day of the chosen horizon's chart.
     *
     * @return list<?float>
     */
    #[Computed]
    public function referenceSkillByDay(): array
    {
        return $this->score === null ? [] : ReferenceSkill::byDay($this->score, $this->referenceAccuracy);
    }

    /**
     * The chosen horizon's score, or the longest one scored while it has not
     * come true yet; render() then moves the choice onto it.
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

    /**
     * @return list<Change>
     */
    #[Computed]
    public function changes(): array
    {
        return new ForecastChanges($this->selectedSensor?->id)->all();
    }
}

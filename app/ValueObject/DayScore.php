<?php

declare(strict_types=1);

namespace App\ValueObject;

use JsonSerializable;

/**
 * One local day of a horizon's forecasts; shown and base on the hours that have a base.
 *
 * @phpstan-type DayRow array{date: string, shown: ?ScoreFigures, base: ?ScoreFigures, modelTookOver: ?string, correctionTookOver: ?int, experiment: ?ScoreFigures}
 */
final readonly class DayScore implements JsonSerializable
{
    /**
     * @param  ?ScoreFigures  $shown  null on a day with nothing scored
     */
    public function __construct(
        public string $date,
        public ?ScoreFigures $shown = null,
        public ?ScoreFigures $base = null,
        public ?string $modelTookOver = null,
        public ?int $correctionTookOver = null,
        public ?ScoreFigures $experiment = null,
    ) {}

    public function withExperiment(?ScoreFigures $experiment): self
    {
        return new self($this->date, $this->shown, $this->base, $this->modelTookOver, $this->correctionTookOver, $experiment);
    }

    /**
     * @return DayRow
     */
    public function jsonSerialize(): array
    {
        return [
            'date' => $this->date,
            'shown' => $this->shown,
            'base' => $this->base,
            'modelTookOver' => $this->modelTookOver,
            'correctionTookOver' => $this->correctionTookOver,
            'experiment' => $this->experiment,
        ];
    }
}

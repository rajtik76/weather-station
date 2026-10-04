<?php

declare(strict_types=1);

namespace App\ValueObject;

use JsonSerializable;

/**
 * One local hour of the day a horizon's forecasts were for; misses in °C, bias is the mean reading minus the median.
 *
 * @phpstan-type HourRow array{count: int, inRange: ?float, error: ?float, worst: ?float, bias: ?float, experiment: ?HourOfDayScore}
 */
final readonly class HourOfDayScore implements JsonSerializable
{
    /**
     * @param  ?float  $inRange  null with nothing scored, as the misses
     */
    public function __construct(
        public int $count = 0,
        public ?float $inRange = null,
        public ?float $error = null,
        public ?float $worst = null,
        public ?float $bias = null,
        public ?HourOfDayScore $experiment = null,
    ) {}

    public function withExperiment(?self $experiment): self
    {
        return new self($this->count, $this->inRange, $this->error, $this->worst, $this->bias, $experiment);
    }

    /**
     * @return HourRow
     */
    public function jsonSerialize(): array
    {
        return [
            'count' => $this->count,
            'inRange' => $this->inRange,
            'error' => $this->error,
            'worst' => $this->worst,
            'bias' => $this->bias,
            'experiment' => $this->experiment,
        ];
    }
}

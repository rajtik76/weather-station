<?php

declare(strict_types=1);

namespace App\ValueObject;

use JsonSerializable;

/**
 * Forecasts scored: skill in %, misses and range width in °C, share in range in %.
 *
 * @phpstan-type Figures array{count: int, skill: ?float, error: ?float, naive: ?float, inRange: float, width: float}
 */
final readonly class ScoreFigures implements JsonSerializable
{
    /**
     * @param  ?float  $skill  null without a naive miss to beat
     * @param  ?float  $error  null without a naive guess to pair with
     */
    public function __construct(
        public int $count,
        public ?float $skill,
        public ?float $error,
        public ?float $naive,
        public float $inRange,
        public float $width,
    ) {}

    /**
     * @return Figures
     */
    public function toArray(): array
    {
        return [
            'count' => $this->count,
            'skill' => $this->skill,
            'error' => $this->error,
            'naive' => $this->naive,
            'inRange' => $this->inRange,
            'width' => $this->width,
        ];
    }

    /**
     * @return Figures
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

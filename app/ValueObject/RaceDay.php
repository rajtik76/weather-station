<?php

declare(strict_types=1);

namespace App\ValueObject;

use JsonSerializable;

/**
 * One local day of the model race: per block each entrant's points, the mean absolute miss of its median in °C.
 *
 * @phpstan-type Points array<string, array<string, float>>
 * @phpstan-type DayRow array{date: string, points: Points}
 */
final readonly class RaceDay implements JsonSerializable
{
    /**
     * @param  Points  $points  by RaceBlock value, then entrant; a block with nothing scored is absent
     */
    public function __construct(
        public string $date,
        public array $points = [],
    ) {}

    /**
     * @return DayRow
     */
    public function jsonSerialize(): array
    {
        return ['date' => $this->date, 'points' => $this->points];
    }
}

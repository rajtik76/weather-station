<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Enums\RaceBlock;
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

    /** Fewest points that day; a tie goes to the earlier of RaceEntrants::NAMES. */
    public function winner(RaceBlock $block): ?string
    {
        $points = $this->points[$block->value] ?? [];

        if ($points === []) {
            return null;
        }

        $order = array_flip(RaceEntrants::NAMES);
        $names = array_keys($points);
        usort($names, fn (string $a, string $b): int => [$points[$a], $order[$a] ?? PHP_INT_MAX] <=> [$points[$b], $order[$b] ?? PHP_INT_MAX]);

        return $names[0];
    }

    /**
     * @return DayRow
     */
    public function jsonSerialize(): array
    {
        return ['date' => $this->date, 'points' => $this->points];
    }
}

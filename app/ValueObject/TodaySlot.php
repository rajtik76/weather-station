<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Models\Forecast;
use JsonSerializable;

/**
 * One ten-minute slot of today: the reading and the medians forecast for it from one horizon earlier, in °C.
 *
 * @phpstan-import-type Band from Forecast
 *
 * @phpstan-type SlotRow array{at: int, clock: string, measured: ?float, shown: ?Band, base: ?float, experiment: ?float}
 */
final readonly class TodaySlot implements JsonSerializable
{
    /**
     * @param  ?Band  $shown
     */
    public function __construct(
        public int $at,
        public string $clock,
        public ?float $measured = null,
        public ?array $shown = null,
        public ?float $base = null,
        public ?float $experiment = null,
    ) {}

    /**
     * @return SlotRow
     */
    public function jsonSerialize(): array
    {
        return [
            'at' => $this->at,
            'clock' => $this->clock,
            'measured' => $this->measured,
            'shown' => $this->shown,
            'base' => $this->base,
            'experiment' => $this->experiment,
        ];
    }
}

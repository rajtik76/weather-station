<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Models\Forecast;

/**
 * A stored forecast's issue window and horizons, apart from its row.
 *
 * @phpstan-import-type Horizon from Forecast
 */
final readonly class IssuedForecast
{
    /**
     * @param  non-empty-list<Horizon>  $horizons  by hours, ascending
     */
    public function __construct(
        public int $issuedAt,
        public array $horizons,
    ) {}
}

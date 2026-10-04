<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Models\Forecast;
use InvalidArgumentException;

/**
 * A stored forecast's issue window and horizons, apart from its row.
 *
 * @phpstan-import-type Horizon from Forecast
 */
final readonly class IssuedForecast
{
    /** @var non-empty-list<Horizon> by hours, ascending */
    public array $horizons;

    /**
     * @param  list<Horizon>  $horizons  by hours, ascending
     */
    public function __construct(
        public int $issuedAt,
        array $horizons,
    ) {
        if ($horizons === []) {
            throw new InvalidArgumentException("Forecast issued at {$issuedAt} has no horizons.");
        }

        $this->horizons = $horizons;
    }
}

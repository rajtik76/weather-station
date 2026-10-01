<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Sensor;
use Illuminate\Database\Eloquent\Collection;

/**
 * The stations a page can show, for its sensor picker: every sensor but the
 * ČHMÚ reference (Sensor::stations()), oldest registration first, so the
 * original station stays the default.
 */
final readonly class Stations
{
    /**
     * @return Collection<int, Sensor>
     */
    public function all(): Collection
    {
        return Sensor::query()->stations()->orderBy('id')->get();
    }
}

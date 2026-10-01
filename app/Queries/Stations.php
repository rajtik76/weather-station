<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Sensor;
use Illuminate\Database\Eloquent\Collection;

/** Stations for the picker, oldest first so the original stays the default. */
final readonly class Stations
{
    /**
     * @return Collection<int, Sensor>
     */
    public function all(): Collection
    {
        return Sensor::query()->orderBy('id')->get();
    }
}

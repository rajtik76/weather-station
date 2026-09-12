<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\StationEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Something done to the station that changed what it reads: a radiation
 * shield fitted, the sensor moved to the other side of the house. Drawn as a
 * vertical line on the charts so a step in the record has a reason next to it.
 *
 * @property int $occurred_at UTC epoch seconds, the cast unwraps the column
 * @property string $title
 * @property ?string $color Any CSS colour; null leaves the chart to pick a neutral one
 */
#[Fillable(['occurred_at', 'title', 'color'])]
class StationEvent extends Model
{
    /** @use HasFactory<StationEventFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'timestamp',
        ];
    }
}

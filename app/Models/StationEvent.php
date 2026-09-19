<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\StationEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something done to a station that changed what it reads, marked on its charts.
 *
 * @property int $sensor_id
 * @property int $occurred_at UTC epoch seconds, the cast unwraps the column
 * @property string $title
 * @property ?string $color Any CSS colour; null leaves the chart to pick a neutral one
 */
#[Fillable(['sensor_id', 'occurred_at', 'title', 'color'])]
class StationEvent extends Model
{
    /** @use HasFactory<StationEventFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Sensor, $this>
     */
    public function sensor(): BelongsTo
    {
        return $this->belongsTo(Sensor::class);
    }

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

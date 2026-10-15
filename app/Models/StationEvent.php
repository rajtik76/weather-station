<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\StationEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Date;

/**
 * Something done to a station that changed what it reads, marked on its charts.
 *
 * @property int $sensor_id
 * @property int $occurred_at UTC epoch seconds
 * @property string $title
 * @property ?string $color Any CSS colour; null is neutral
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
     * Events after one instant, up to and including another (UTC epoch seconds).
     *
     * @param  Builder<StationEvent>  $query
     */
    #[Scope]
    protected function between(Builder $query, int $sensorId, int $after, int $upTo): void
    {
        $query->where('sensor_id', $sensorId)
            ->where('occurred_at', '>', Date::createFromTimestampUTC($after))
            ->where('occurred_at', '<=', Date::createFromTimestampUTC($upTo));
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

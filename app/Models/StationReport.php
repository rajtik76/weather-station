<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\StationReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What the board said about itself alongside one upload: firmware, why it
 * last booted, uptime, heap, which network it is on and how the link has
 * been behaving. One row per batch, so a stall can be read back from the
 * record after the station recovered - the flash log on the board says what
 * happened, this says when it started going wrong.
 *
 * @property int $sensor_id
 * @property array<string, mixed> $data The `station` object as the firmware sent it
 */
#[Fillable(['sensor_id', 'data'])]
class StationReport extends Model
{
    /** @use HasFactory<StationReportFactory> */
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
            'data' => 'array',
        ];
    }
}

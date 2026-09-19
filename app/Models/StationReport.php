<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\StationReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The `station` object of one upload, one row per batch.
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

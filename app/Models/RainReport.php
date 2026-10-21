<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RainReportSource;
use Database\Factories\RainReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ground truth for the rain detector: what it heard in a window and what the owner saw.
 *
 * @property int $id
 * @property int $sensor_id
 * @property RainReportSource $source
 * @property int $timestamp UTC epoch seconds; the window end for a detector report
 * @property ?bool $detected Null when no fresh noise window was at hand
 * @property ?bool $raining Null until the owner answers
 * @property ?string $discord_message_id
 */
#[Fillable(['sensor_id', 'source', 'timestamp', 'detected', 'raining', 'discord_message_id'])]
class RainReport extends Model
{
    /** @use HasFactory<RainReportFactory> */
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
            'source' => RainReportSource::class,
            'timestamp' => 'integer',
            'detected' => 'boolean',
            'raining' => 'boolean',
        ];
    }
}

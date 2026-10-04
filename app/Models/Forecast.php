<?php

declare(strict_types=1);

namespace App\Models;

use App\ValueObject\ChartWindow;
use Database\Factories\ForecastFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One forecast service run; T, H and P are 10-90 % ranges around the median (°C, %, hPa).
 * `base` is the forecast before station correction; its `rain_probability` is uncapped by nest_rain(), absent on older rows.
 * `nwp` is the numerical weather model's temperature (°C) for the same window, fetched at issue time.
 *
 * @phpstan-type Band array{low: float, mid: float, high: float}
 * @phpstan-type Horizon array{hours: int, temperature: Band, humidity: Band, pressure: Band, rain_probability: float, base?: array{temperature: Band, humidity: Band, rain_probability?: float}, nwp?: array{temperature: float}, experiment?: array{version: string, temperature: Band, synthetic?: bool}}
 *
 * @property int $sensor_id
 * @property int $issued_at
 * @property string $model
 * @property bool $corrected
 * @property int|null $correction CORRECTION_VERSION of the station correction's logic; null when the service sent none
 * @property list<Horizon> $data
 */
#[Fillable(['sensor_id', 'issued_at', 'model', 'corrected', 'correction', 'data'])]
class Forecast extends Model
{
    /** One forecast per sensor and upload window. */
    public const int INTERVAL_SECONDS = ChartWindow::STEP_SECONDS;

    /** @use HasFactory<ForecastFactory> */
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
            'issued_at' => 'integer',
            'corrected' => 'boolean',
            'correction' => 'integer',
            'data' => 'array',
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ForecastFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One forecast service run; T, H and P are 10-90 % ranges around the median (°C, %, hPa).
 * `base` is the forecast before station correction, absent until
 * `forecast:backfill-base`. Its `rain_probability` is the classifier's answer
 * before nest_rain() capping (forecast/forecast.py); forecasts before v4.0.6 have none.
 * `nwp` is the numerical weather model's temperature (°C) for the same window, fetched at issue time.
 *
 * @phpstan-type Band array{low: float, mid: float, high: float}
 * @phpstan-type Horizon array{hours: int, temperature: Band, humidity: Band, pressure: Band, rain_probability: float, base?: array{temperature: Band, humidity: Band, rain_probability?: float}, nwp?: array{temperature: float}}
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

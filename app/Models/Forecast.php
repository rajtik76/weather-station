<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ForecastFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One run of the forecast service, issued from a station's latest reading.
 * Values are in °C, % and hPa as the service returns them; T, H and P come
 * as a 10-90 % range around the median. `base` is the same forecast before
 * the station correction, for the two variables it corrects; a forecast
 * stored before the service returned it has none until
 * `forecast:backfill-base` fills it in.
 *
 * @phpstan-type Band array{low: float, mid: float, high: float}
 * @phpstan-type Horizon array{hours: int, temperature: Band, humidity: Band, pressure: Band, rain_probability: float, base?: array{temperature: Band, humidity: Band}}
 *
 * @property int $sensor_id
 * @property int $issued_at
 * @property string $model
 * @property bool $corrected
 * @property list<Horizon> $data
 */
#[Fillable(['sensor_id', 'issued_at', 'model', 'corrected', 'data'])]
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
            'data' => 'array',
        ];
    }
}

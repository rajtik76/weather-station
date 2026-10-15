<?php

declare(strict_types=1);

namespace App\Models;

use App\ValueObject\ShieldFeatures;
use App\ValueObject\ShieldModel;
use App\ValueObject\TemperatureScore;
use Database\Factories\TemperatureCalibrationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @phpstan-import-type Score from TemperatureScore
 *
 * @property int $sensor_id
 * @property int $feature_version
 * @property int $trained_from
 * @property int $trained_until
 * @property int $validated_until
 * @property bool $accepted
 * @property array{latitude: float, longitude: float, wall_azimuth: float} $geometry
 * @property list<float> $coefficients
 * @property array{max_lux: float, max_heating: float} $limits
 * @property array{training: array<string, Score>, validation: array<string, Score>, days: array<string, Score>} $scores
 */
class TemperatureCalibration extends Model
{
    /** @use HasFactory<TemperatureCalibrationFactory> */
    use HasFactory;

    /** @return BelongsTo<Sensor, $this> */
    public function sensor(): BelongsTo
    {
        return $this->belongsTo(Sensor::class);
    }

    public function features(): ShieldFeatures
    {
        return new ShieldFeatures($this->geometry['latitude'], $this->geometry['longitude'], $this->geometry['wall_azimuth']);
    }

    public function model(): ShieldModel
    {
        return new ShieldModel($this->coefficients, $this->limits['max_lux'], $this->limits['max_heating']);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'feature_version' => 'integer',
            'trained_from' => 'integer',
            'trained_until' => 'integer',
            'validated_until' => 'integer',
            'accepted' => 'boolean',
            'geometry' => 'array',
            'coefficients' => 'array',
            'limits' => 'array',
            'scores' => 'array',
        ];
    }
}

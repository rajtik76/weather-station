<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SensorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One station, created by the first upload under a new `name`. `slug` is set
 * once so `?sensor=` links keep working. The ČHMÚ reference row
 * (forecast.reference) is no station: stations() keeps it out of the picker.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property ?string $description
 */
#[Fillable(['name', 'slug', 'description'])]
class Sensor extends Model
{
    /** @use HasFactory<SensorFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Sensor $sensor): void {
            $sensor->slug ??= self::uniqueSlug($sensor->name);
        });
    }

    /** The reference row, created on first use. */
    public static function reference(): self
    {
        return self::query()->firstOrCreate(['name' => (string) config('forecast.reference.name')]);
    }

    /** The reference row, or null before the job has run. */
    public static function findReference(): ?self
    {
        return self::query()->where('name', (string) config('forecast.reference.name'))->first();
    }

    /**
     * Every sensor but the reference.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function stations(Builder $query): void
    {
        $query->where('name', '!=', (string) config('forecast.reference.name'));
    }

    /** Names can slug alike; a symbols-only name slugs to ''. */
    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'sensor';
        $slug = $base;

        for ($suffix = 2; self::query()->where('slug', $slug)->exists(); $suffix++) {
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }

    /**
     * @return HasMany<Measurement, $this>
     */
    public function measurements(): HasMany
    {
        return $this->hasMany(Measurement::class);
    }

    /**
     * @return HasMany<StationEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(StationEvent::class);
    }

    /**
     * @return HasMany<StationReport, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(StationReport::class);
    }

    /**
     * @return HasMany<Forecast, $this>
     */
    public function forecasts(): HasMany
    {
        return $this->hasMany(Forecast::class);
    }
}

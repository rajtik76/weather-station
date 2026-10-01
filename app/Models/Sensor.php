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
 * One station. `name` is what the firmware sends; the first upload under a
 * new name creates the row. `slug` is derived once, on creation, so a
 * `?sensor=` link keeps working. One row is no station of ours: the ČHMÚ
 * reference (config forecast.reference), forecast every night as a yardstick
 * and kept out of the picker by stations().
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

    /** The reference station's row, created on first use by the job that fills it. */
    public static function reference(): self
    {
        return self::query()->firstOrCreate(['name' => (string) config('forecast.reference.name')]);
    }

    /** The reference station's row, or null before the job has run once. */
    public static function findReference(): ?self
    {
        return self::query()->where('name', (string) config('forecast.reference.name'))->first();
    }

    /**
     * The stations whose own uploads the pages show: every sensor but the reference.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function stations(Builder $query): void
    {
        $query->where('name', '!=', (string) config('forecast.reference.name'));
    }

    /** Two names can slug alike, and a name of symbols only slugs to ''. */
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

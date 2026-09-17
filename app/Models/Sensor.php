<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SensorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One physical station: a board with its BME280, wherever it hangs.
 *
 * The name is the identifier the firmware sends with every upload, and the
 * first upload under a new name creates the row. The description is written
 * by hand afterwards and is what the dashboard shows beside the name.
 *
 * The slug is the name as a URL can carry it - the firmware may call itself
 * anything within 50 characters - and it is what `?sensor=` holds. Derived
 * once, on creation, so a link keeps working.
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

    /**
     * A slug no other sensor has.
     *
     * Two names can slug alike ("Sensor 1" and "sensor-1"), and a name of
     * nothing but symbols slugs to an empty string, so the second gets a
     * counter and the empty one a word.
     */
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
}

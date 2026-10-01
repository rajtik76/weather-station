<?php

declare(strict_types=1);

namespace App\Livewire;

use App\ValueObject\CarriesNoise;
use App\ValueObject\DayFigures;
use App\ValueObject\NoiseWindow;
use App\ValueObject\RainDetector;
use App\ValueObject\Readout;
use App\ValueObject\StationSite;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;

/**
 * The front page of the redesign: what the station reads now, the next six
 * hours and how well that forecast has scored. The only page that polls.
 *
 * `#[Computed]` methods are declared as properties for Larastan.
 *
 * @phpstan-import-type DayRow from Readout
 * @phpstan-import-type Figures from DayFigures
 *
 * @property-read list<DayRow> $lastDay
 * @property-read array<string, Figures> $metrics
 * @property-read ?float $dewPoint
 * @property-read ?bool $rainHeard
 * @property-read list<string> $silentChannels
 * @property-read array{lat: float, lng: float, radius: int} $approximateLocation
 */
#[Title('Balcony Station')]
class Overview extends StationPage
{
    /** The map blurs the balcony into a circle this wide: the page is public. */
    private const int LOCATION_RADIUS_METRES = 800;

    public function render(): View
    {
        return view('livewire.overview');
    }

    /**
     * The trailing 24 hours, or the newest reading alone when the station has
     * been quiet for longer.
     *
     * @return list<DayRow>
     */
    #[Computed]
    public function lastDay(): array
    {
        $day = $this->record()->readoutsSince(now()->subDay()->getTimestamp());

        if ($day === [] && $this->newestMeasurement !== null) {
            return [Readout::of($this->newestMeasurement->data)->toArray()];
        }

        return $day;
    }

    /**
     * @return array<string, Figures>
     */
    #[Computed]
    public function metrics(): array
    {
        return $this->lastDay === [] ? [] : DayFigures::of($this->lastDay)->metrics();
    }

    /**
     * The day's noise and light that the newest window no longer carries: a
     * dead microphone or light sensor leaves the day's figures standing, and
     * their "now" is then the last value read, not the present.
     *
     * @return list<string>
     */
    #[Computed]
    public function silentChannels(): array
    {
        $newest = $this->lastDay === [] ? null : $this->lastDay[array_key_last($this->lastDay)];

        return array_values(array_filter(
            ['n', 'l'],
            fn (string $key): bool => isset($this->metrics[$key]) && ($newest[$key] ?? null) === null,
        ));
    }

    #[Computed]
    public function dewPoint(): ?float
    {
        return $this->newestMeasurement === null ? null : Readout::of($this->newestMeasurement->data)->dewPoint();
    }

    /**
     * Whether the microphone hears rain in the newest window. Null without a
     * spectrum or while the station is silent: neither may read as a dry sky.
     */
    #[Computed]
    public function rainHeard(): ?bool
    {
        $data = $this->newestMeasurement?->data;

        if ($this->isSilent || ! $data instanceof CarriesNoise || ! $data->noise instanceof NoiseWindow) {
            return null;
        }

        return RainDetector::hearsStored($data->noise->bands);
    }

    /**
     * @return array{lat: float, lng: float, radius: int}
     */
    #[Computed]
    public function approximateLocation(): array
    {
        return [
            'lat' => StationSite::LATITUDE,
            'lng' => StationSite::LONGITUDE,
            'radius' => self::LOCATION_RADIUS_METRES,
        ];
    }
}

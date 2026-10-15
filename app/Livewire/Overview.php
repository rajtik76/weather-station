<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\Channel;
use App\Queries\TemperatureHistory;
use App\ValueObject\CarriesNoise;
use App\ValueObject\DayFigures;
use App\ValueObject\NoiseWindow;
use App\ValueObject\RainDetector;
use App\ValueObject\Readout;
use App\ValueObject\StationSite;
use App\ValueObject\TemperatureTrace;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;

/**
 * The front page; the only one that polls.
 *
 * @phpstan-import-type DayRow from Readout
 * @phpstan-import-type Figures from DayFigures
 *
 * @property-read list<DayRow> $lastDay
 * @property-read array<string, Figures> $metrics
 * @property-read list<Channel> $channels
 * @property-read ?float $dewPoint
 * @property-read TemperatureTrace $temperatureTrace
 * @property-read ?bool $rainHeard
 * @property-read list<Channel> $silentChannels
 * @property-read array{lat: float, lng: float, radius: int} $approximateLocation
 */
#[Title('Balcony Station')]
class Overview extends StationPage
{
    #[Computed]
    public function temperatureTrace(): TemperatureTrace
    {
        $newest = $this->newestMeasurement?->timestamp;
        $from = $newest === null ? $this->dayStart() : min($this->dayStart(), $newest);
        $readings = resolve(TemperatureHistory::class)->readings($this->selectedSensor?->id, $from, now()->addHour()->getTimestamp());

        return new TemperatureTrace(array_slice($readings, 0, count($this->lastDay)));
    }

    /** Blurs the public map into a circle this wide. */
    private const int LOCATION_RADIUS_METRES = 800;

    /** Start of the trailing 24 hours, fixed per request so the measured and estimated traces share rows. */
    private ?int $dayStart = null;

    /** Stamp of the newest reading already on screen; null before the station's first. */
    #[Locked]
    public ?int $shownReadingAt = null;

    public function mount(): void
    {
        parent::mount();
        $this->shownReadingAt = $this->newestMeasurement?->timestamp;
    }

    public function updatedSensor(): void
    {
        parent::updatedSensor();
        unset($this->newestMeasurement);
        $this->shownReadingAt = $this->newestMeasurement?->timestamp;
    }

    private function dayStart(): int
    {
        return $this->dayStart ??= now()->subDay()->getTimestamp();
    }

    public function render(): View
    {
        $newest = $this->newestMeasurement?->timestamp;
        $readingArrived = $newest !== null && ($this->shownReadingAt === null || $newest > $this->shownReadingAt);
        $this->shownReadingAt = $newest ?? $this->shownReadingAt;

        return view('livewire.overview', ['readingArrived' => $readingArrived]);
    }

    /**
     * The trailing 24 hours, or the newest reading alone if the station is quieter than that.
     *
     * @return list<DayRow>
     */
    #[Computed]
    public function lastDay(): array
    {
        $day = $this->record()->readoutsSince($this->dayStart());

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
     * Channels with day figures.
     *
     * @return list<Channel>
     */
    #[Computed]
    public function channels(): array
    {
        return array_values(array_filter(Channel::cases(), fn (Channel $channel): bool => isset($this->metrics[$channel->value])));
    }

    /**
     * Noise and light channels with day figures but missing from the newest window, so their "now" is stale.
     *
     * @return list<Channel>
     */
    #[Computed]
    public function silentChannels(): array
    {
        $newest = $this->lastDay === [] ? null : $this->lastDay[array_key_last($this->lastDay)];

        return array_values(array_filter(
            [Channel::Noise, Channel::Light],
            fn (Channel $channel): bool => isset($this->metrics[$channel->value]) && ($newest[$channel->value] ?? null) === null,
        ));
    }

    #[Computed]
    public function dewPoint(): ?float
    {
        return $this->newestMeasurement === null ? null : Readout::of($this->newestMeasurement->data)->dewPoint();
    }

    /** Null without a spectrum or while the station is silent: neither may read as a dry sky. */
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

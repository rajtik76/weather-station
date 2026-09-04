<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\ChartRange;
use App\Models\Measurement;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Date;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use UnexpectedValueException;

/**
 * Livewire resolves `#[Computed]` methods as properties at runtime. Larastan
 * does not model that, so they are declared here to stay analysable.
 *
 * @property-read list<array{0: int, 1: float, 2: float, 3: float, 4: int}> $readings
 * @property-read list<array{0: int, 1: float, 2: float, 3: float, 4: int}> $overview
 * @property-read array{from: int, to: int} $windowMs
 * @property-read bool $hasReadings
 * @property-read list<array{t: float, h: float, p: float}> $lastDay
 * @property-read array<string, array{now: float, delta: float, dayMin: float, dayMax: float, min: float, max: float, avg: float}> $metrics
 * @property-read array{lat: float, lng: float, radius: int} $approximateLocation
 * @property-read int $currentYear
 * @property-read CarbonInterface|null $lastTransmission
 * @property-read string|null $measuredAt
 * @property-read string|null $measuredAgo
 * @property-read bool $isSilent
 * @property-read array{from: string, to: string} $window
 * @property-read bool $isZoomed
 */
#[Title('Weather Station')]
class Dashboard extends Component
{
    /** Measurements arrive from the ESP32 every 10 minutes. */
    private const int STEP_SECONDS = 600;

    /**
     * The station stands in Plzeň, so every label reads in Czech local time.
     *
     * Stored stamps are UTC epochs (the firmware sends `time(nullptr)`), and the
     * app clock stays on UTC; only the presentation layer shifts.
     */
    private const string DISPLAY_TIMEZONE = 'Europe/Prague';

    /** Sensor location: Galerie Slovany, náměstí Generála Píky, Plzeň-Slovany. */
    private const float LATITUDE = 49.732343;

    private const float LONGITUDE = 13.400984;

    /** The map draws this radius as a circle, with nothing marking its centre. */
    private const int LOCATION_RADIUS_METRES = 500;

    /** Two missed slots, and the station is down rather than merely late. */
    private const int SILENT_AFTER_SECONDS = 3 * self::STEP_SECONDS;

    /** The navigator spans everything, so it is thinned to one point per bucket. */
    private const int OVERVIEW_BUCKET_SECONDS = 21600;

    /** Where the page opens, and where "reset" returns to. */
    private const ChartRange DEFAULT_WINDOW = ChartRange::Week;

    /** Below this a zoom would frame fewer readings than make a line. */
    private const int MIN_SPAN_SECONDS = 4 * self::STEP_SECONDS;

    /**
     * The zoomed window as UTC epoch seconds, or null to follow the preset.
     *
     * Held as real instants rather than as a count of preset-sized steps, so
     * that dragging a selection can land anywhere rather than on a grid.
     */
    #[Url]
    public ?int $from = null;

    #[Url]
    public ?int $to = null;

    public function mount(): void
    {
        $this->normaliseWindow();
    }

    public function render(): View
    {
        return view('livewire.dashboard');
    }

    /** Called from the chart once a drag-selection settles. */
    public function zoomTo(int $from, int $to): void
    {
        $this->from = $from;
        $this->to = $to;

        $this->normaliseWindow();
    }

    public function resetZoom(): void
    {
        $this->from = null;
        $this->to = null;
    }

    #[Computed]
    public function isZoomed(): bool
    {
        return $this->from !== null && $this->to !== null;
    }

    /**
     * Readings for the window, oldest first, as compact rows.
     *
     * Arrays rather than keyed objects because this is the chart payload and
     * a week runs to a thousand rows. Each row is
     * `[wall-clock ms, °C, %, hPa, real epoch seconds]`.
     *
     * The first element is shifted to Czech local time and the chart is told
     * to read it as UTC, which is what makes the axis and tooltip read local
     * without depending on the viewer's own clock. It is therefore not a real
     * instant - the last element is, and that is what a zoom sends back.
     *
     * @return list<array{0: int, 1: float, 2: float, 3: float, 4: int}>
     */
    #[Computed]
    public function readings(): array
    {
        $thinTo = ChartRange::forSpan($this->spanSeconds())->thinToSeconds();

        return $this->plot(
            Measurement::query()
                ->whereBetween('timestamp', [$this->windowFrom(), $this->windowTo()])
                // Keep the first reading of each bucket. The modulo runs on the
                // indexed column, so the range scan still does the narrowing.
                ->when($thinTo > 0, fn (Builder $query): Builder => $query
                    ->whereRaw('timestamp % ? < ?', [$thinTo, self::STEP_SECONDS]))
                ->orderBy('timestamp')
                ->get()
        );
    }

    /**
     * Every reading the station ever sent, thinned hard, for the navigator.
     *
     * The navigator exists to show where the window sits in the whole record,
     * so it always spans everything regardless of the preset. One point per
     * six hours is far below what its few pixels can resolve, which keeps this
     * cheap even once the table runs to years.
     *
     * @return list<array{0: int, 1: float, 2: float, 3: float, 4: int}>
     */
    #[Computed]
    public function overview(): array
    {
        return $this->plot(
            Measurement::query()
                ->whereRaw('timestamp % ? < ?', [self::OVERVIEW_BUCKET_SECONDS, self::STEP_SECONDS])
                ->orderBy('timestamp')
                ->get()
        );
    }

    /**
     * The window as the navigator's own axis reads it.
     *
     * Wall-clock milliseconds, matching the stamps in the payload, so the
     * slider can be positioned without converting anything in the browser.
     *
     * @return array{from: int, to: int}
     */
    #[Computed]
    public function windowMs(): array
    {
        return [
            'from' => $this->wallClockMs($this->windowFrom()),
            'to' => $this->wallClockMs($this->windowTo()),
        ];
    }

    /** No rows in the window: the station was quiet, or has never reported. */
    #[Computed]
    public function hasReadings(): bool
    {
        return $this->readings !== [];
    }

    /**
     * The window on screen, for the reader to see where they are.
     *
     * @return array{from: string, to: string}
     */
    #[Computed]
    public function window(): array
    {
        $format = ChartRange::forSpan($this->spanSeconds())->stampFormat();

        return [
            'from' => $this->localise($this->windowFrom())->format($format),
            'to' => $this->localise($this->windowTo())->format($format),
        ];
    }

    /**
     * The trailing 24 hours, for the hero readouts.
     *
     * Queried separately rather than sliced off `readings`, so that zooming
     * into an hour does not shrink what "now" and "24 h" mean - and so that a
     * thinned long window does not silently stretch them either.
     *
     * Falls back to the newest reading on screen when the station has been
     * quiet for over a day, so the cards still show its last state.
     *
     * @return list<array{t: float, h: float, p: float}>
     */
    #[Computed]
    public function lastDay(): array
    {
        $day = array_values(
            Measurement::query()
                ->where('timestamp', '>=', now()->subDay()->getTimestamp())
                ->orderBy('timestamp')
                ->get()
                ->map(fn (Measurement $measurement): array => [
                    't' => round($measurement->data->temperature / 100, 2),
                    'h' => round($measurement->data->humidity / 100, 2),
                    'p' => round($measurement->data->pressure / 100, 1),
                ])
                ->all()
        );

        if ($day !== []) {
            return $day;
        }

        return array_map(fn (array $row): array => [
            't' => $row[1],
            'h' => $row[2],
            'p' => $row[3],
        ], array_slice($this->readings, -1));
    }

    /**
     * @return array<string, array{now: float, delta: float, dayMin: float, dayMax: float, min: float, max: float, avg: float}>
     */
    #[Computed]
    public function metrics(): array
    {
        if (! $this->hasReadings) {
            return [];
        }

        return [
            't' => $this->figures('t', 1),
            'h' => $this->figures('h', 2),
            'p' => $this->figures('p', 3),
        ];
    }

    /**
     * Centre and radius of the area shown on the location map.
     *
     * @return array{lat: float, lng: float, radius: int}
     */
    #[Computed]
    public function approximateLocation(): array
    {
        return [
            'lat' => self::LATITUDE,
            'lng' => self::LONGITUDE,
            'radius' => self::LOCATION_RADIUS_METRES,
        ];
    }

    /** Copyright year, read off the station's own clock rather than UTC. */
    #[Computed]
    public function currentYear(): int
    {
        return now(self::DISPLAY_TIMEZONE)->year;
    }

    /**
     * When the station last reported anything, across the whole table rather
     * than the window - zooming in must not make the station look silent.
     *
     * This is a real instant, unlike the stamps in the chart payload, so it is
     * the only date here that may be measured against now().
     */
    #[Computed]
    public function lastTransmission(): ?CarbonInterface
    {
        $timestamp = Measurement::query()->max('timestamp');

        return $timestamp === null
            ? null
            : Date::createFromTimestamp((int) $timestamp, self::DISPLAY_TIMEZONE);
    }

    #[Computed]
    public function measuredAt(): ?string
    {
        return $this->lastTransmission?->format('j. n. Y H:i');
    }

    /** Relative wording, which is what tells you at a glance if it is late. */
    #[Computed]
    public function measuredAgo(): ?string
    {
        return $this->lastTransmission?->diffForHumans();
    }

    /** Nothing for three slots running: treat the station as off the air. */
    #[Computed]
    public function isSilent(): bool
    {
        return $this->lastTransmission === null
            || $this->lastTransmission->getTimestamp() < now()->getTimestamp() - self::SILENT_AFTER_SECONDS;
    }

    /** Oldest instant on screen, as a real UTC epoch. */
    private function windowFrom(): int
    {
        return $this->from ?? now()->getTimestamp() - self::DEFAULT_WINDOW->durationSeconds();
    }

    /** Newest instant on screen, as a real UTC epoch. */
    private function windowTo(): int
    {
        return $this->to ?? now()->getTimestamp();
    }

    private function spanSeconds(): int
    {
        return $this->windowTo() - $this->windowFrom();
    }

    /**
     * Both ends arrive from the query string, where anything can be typed, and
     * from a drag that may have been a stray click. Keep them ordered, wide
     * enough to draw, and out of the future.
     */
    private function normaliseWindow(): void
    {
        if ($this->from === null || $this->to === null) {
            $this->from = null;
            $this->to = null;

            return;
        }

        if ($this->from > $this->to) {
            [$this->from, $this->to] = [$this->to, $this->from];
        }

        $this->to = min($this->to, now()->getTimestamp());
        $this->from = max(0, min($this->from, $this->to - self::MIN_SPAN_SECONDS));
    }

    /** The same instant, read off the station's clock instead of UTC. */
    private function localise(int $timestamp): CarbonInterface
    {
        return Date::createFromTimestamp($timestamp, self::DISPLAY_TIMEZONE);
    }

    /**
     * A UTC epoch as milliseconds of Czech wall-clock time.
     *
     * The offset is folded into the value and the chart is set to read its
     * time axis as UTC, so ticks and tooltips print Czech local time whatever
     * clock the viewer's machine is on, and ticks still land on local
     * midnight rather than an hour off it.
     *
     * The result is a wall-clock reading, not an instant: it must never be
     * measured against now() or converted a second time. Rows carry the real
     * epoch alongside for anything that needs one.
     */
    private function wallClockMs(int $timestamp): int
    {
        return ($timestamp + $this->localise($timestamp)->utcOffset() * 60) * 1000;
    }

    /**
     * Chart rows for a set of measurements, oldest first.
     *
     * Columns hold the raw protocol units (see ProtocolVersion::V1):
     * temperature and humidity in hundredths, pressure in pascals. The chart
     * wants °C, % and hPa.
     *
     * @param  Collection<int, Measurement>  $measurements
     * @return list<array{0: int, 1: float, 2: float, 3: float, 4: int}>
     */
    private function plot(Collection $measurements): array
    {
        return array_values(
            $measurements
                ->map(fn (Measurement $measurement): array => [
                    $this->wallClockMs($measurement->timestamp),
                    round($measurement->data->temperature / 100, 2),
                    round($measurement->data->humidity / 100, 2),
                    round($measurement->data->pressure / 100, 1),
                    $measurement->timestamp,
                ])
                ->all()
        );
    }

    /**
     * Card + panel figures for one metric.
     *
     * @return array{now: float, delta: float, dayMin: float, dayMax: float, min: float, max: float, avg: float}
     */
    private function figures(string $field, int $column): array
    {
        $all = array_column($this->readings, $column);
        $day = array_column($this->lastDay, $field);

        if ($all === [] || $day === []) {
            throw new UnexpectedValueException("No readings to summarise for [{$field}].");
        }

        $now = end($day);

        return [
            'now' => $now,
            // vs. one hour ago, or the oldest point we have if the window is shorter
            'delta' => $now - (float) $day[max(0, count($day) - 7)],
            'dayMin' => min($day),
            'dayMax' => max($day),
            'min' => (float) min($all),
            'max' => (float) max($all),
            'avg' => array_sum($all) / count($all),
        ];
    }
}

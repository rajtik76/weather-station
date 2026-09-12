<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\ChartRange;
use App\Models\Measurement;
use App\Models\StationEvent;
use App\ValueObject\DewPoint;
use App\ValueObject\MeasurementData;
use App\ValueObject\SeaLevelPressure;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Date;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use UnexpectedValueException;

/**
 * Livewire resolves `#[Computed]` methods as properties at runtime. Larastan
 * does not model that, so they are declared here to stay analysable.
 *
 * @property-read list<array{0: int, 1: float, 2: float, 3: float, 4: ?float, 5: int}> $readings
 * @property-read list<array{0: int, 1: float, 2: float, 3: float, 4: ?float, 5: int}> $overview
 * @property-read array{from: int, to: int} $windowMs
 * @property-read bool $hasReadings
 * @property-read list<array{t: float, h: float, p: float}> $lastDay
 * @property-read array<string, array{now: float, delta: float, dayMin: float, dayMax: float}> $metrics
 * @property-read list<array{timestamp: int, temperature: int, humidity: int, pressure: int, at: string, ago: string, t: float, h: float, p: float}> $recentTransmissions
 * @property-read array{lat: float, lng: float, radius: int} $approximateLocation
 * @property-read int $currentYear
 * @property-read CarbonInterface|null $lastMeasurement
 * @property-read string|null $measuredAt
 * @property-read string|null $measuredAgo
 * @property-read bool $isSilent
 * @property-read array{from: string, to: string} $window
 * @property-read bool $isZoomed
 * @property-read list<string> $hiddenChannels
 * @property-read list<array{0: int, 1: string, 2: ?string}> $stationEvents
 */
#[Title('Station Log')]
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
    private const float LATITUDE = 49.733242;

    private const float LONGITUDE = 13.399911;

    /**
     * Height of the sensor above sea level, in metres.
     *
     * The BME280 reads the pressure where it hangs, some 40 hPa below what a
     * forecast quotes. Every figure on this page is reduced to sea level with
     * this height so it can be compared against one; the stored reading stays
     * as the station sent it. See SeaLevelPressure.
     */
    private const float ALTITUDE_METRES = 345.0;

    /** The map draws this radius as a circle, with nothing marking its centre. */
    private const int LOCATION_RADIUS_METRES = 800;

    /** Two missed slots, and the station is down rather than merely late. */
    private const int SILENT_AFTER_SECONDS = 3 * self::STEP_SECONDS;

    /** The navigator spans everything, so it is thinned to one point per bucket. */
    private const int OVERVIEW_BUCKET_SECONDS = 21600;

    /** Under this the whole record is small enough to draw without thinning. */
    private const int OVERVIEW_UNTHINNED_ROWS = 1500;

    /** Where the page opens, and where "reset" returns to. */
    private const ChartRange DEFAULT_WINDOW = ChartRange::Week;

    /** Below this a zoom would frame fewer readings than make a line. */
    private const int MIN_SPAN_SECONDS = 4 * self::STEP_SECONDS;

    /** How many transmissions the payload tail lists. */
    private const int RECENT_TRANSMISSIONS = 3;

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

    /**
     * Which lines the shared strip draws, by channel key.
     *
     * The dew point starts off: it is derived rather than measured, and a
     * third line is clutter for a reader who only came for the weather. See
     * toggleChannel() for why the last one cannot be switched off - locked,
     * so that guard cannot be walked around with `$wire.set()`.
     *
     * @var array<string, bool>
     */
    #[Locked]
    public array $channels = self::DEFAULT_CHANNELS;

    /** @var array<string, bool> */
    private const array DEFAULT_CHANNELS = ['t' => true, 'h' => true, 'd' => false];

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

    /**
     * Switch one of the shared strip's lines on or off.
     *
     * The last line on stays on: an empty strip is a blank band with two
     * axes, which reads as a failure rather than a choice. The template
     * disables that switch as well; this is what holds when it does not.
     */
    public function toggleChannel(string $channel): void
    {
        if (! array_key_exists($channel, self::DEFAULT_CHANNELS)) {
            return;
        }

        $on = $this->channels[$channel] ?? false;

        if ($on && $this->isLastChannel($channel)) {
            return;
        }

        $this->channels[$channel] = ! $on;
    }

    /** Whether the channel is the only one still drawn - and so cannot be switched off. */
    public function isLastChannel(string $channel): bool
    {
        return ($this->channels[$channel] ?? false)
            && count(array_filter($this->channels)) === 1;
    }

    /**
     * Channel keys the chart must not draw, for the payload.
     *
     * Read against the defaults rather than the property as it arrived, so a
     * key missing or added on the client side changes nothing.
     *
     * @return list<string>
     */
    #[Computed]
    public function hiddenChannels(): array
    {
        return array_values(array_filter(
            array_keys(self::DEFAULT_CHANNELS),
            fn (string $channel): bool => ! ($this->channels[$channel] ?? false),
        ));
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
     * `[wall-clock ms, °C, %, hPa, dew point °C, real epoch seconds]`.
     *
     * The first element is shifted to Czech local time and the chart is told
     * to read it as UTC, which is what makes the axis and tooltip read local
     * without depending on the viewer's own clock. It is therefore not a real
     * instant - the last element is, and that is what a zoom sends back.
     *
     * @return list<array{0: int, 1: float, 2: float, 3: float, 4: ?float, 5: int}>
     */
    #[Computed]
    public function readings(): array
    {
        $thinTo = ChartRange::forSpan($this->spanSeconds())->thinToSeconds();

        $window = [$this->windowFrom(), $this->windowTo()];

        return $this->plot(
            Measurement::query()
                ->whereBetween('timestamp', $window)
                ->when($thinTo > 0, fn (Builder $query): Builder => $this->firstPerBucket($query, $thinTo, $window))
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
     * A record shorter than one bucket would thin to a single point, so below
     * OVERVIEW_UNTHINNED_ROWS the whole thing is drawn as it stands. See
     * firstPerBucket() for why the thinning groups rather than counts.
     *
     * @return list<array{0: int, 1: float, 2: float, 3: float, 4: ?float, 5: int}>
     */
    #[Computed]
    public function overview(): array
    {
        $total = Measurement::query()->count();

        return $this->plot(
            Measurement::query()
                ->when(
                    $total >= self::OVERVIEW_UNTHINNED_ROWS,
                    fn (Builder $query): Builder => $this->firstPerBucket($query, self::OVERVIEW_BUCKET_SECONDS)
                )
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
                    'p' => $this->seaLevelHpa($measurement->data),
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
     * @return array<string, array{now: float, delta: float, dayMin: float, dayMax: float}>
     */
    #[Computed]
    public function metrics(): array
    {
        if (! $this->hasReadings) {
            return [];
        }

        return [
            't' => $this->figures('t'),
            'h' => $this->figures('h'),
            'p' => $this->figures('p'),
        ];
    }

    /**
     * The newest transmissions, in the units the station sent them in.
     *
     * Read across the whole table rather than the window, so that zooming into
     * last spring does not empty the tail. Each row keeps the protocol's fixed
     * point integers - that is what the endpoint received and what the blob
     * holds - with the converted figures alongside for the second column, where
     * pressure is the reduced one the rest of the page shows.
     *
     * The date is `created_at`, when the row reached the server - the reading's
     * own stamp is already printed in the JSON beside it, and repeating it as
     * a formatted date would say the same thing twice. Arrival is the other
     * half of the story: a buffered batch lands minutes or hours after it was
     * measured, and this is the only place that shows the gap.
     *
     * @return list<array{timestamp: int, temperature: int, humidity: int, pressure: int, at: string, ago: string, t: float, h: float, p: float}>
     */
    #[Computed]
    public function recentTransmissions(): array
    {
        return array_values(
            Measurement::query()
                ->orderByDesc('timestamp')
                ->limit(self::RECENT_TRANSMISSIONS)
                ->get()
                ->map(function (Measurement $measurement): array {
                    // Rows written before the column existed fall back to the
                    // station's own stamp rather than dropping out of the tail.
                    $receivedAt = $this->localise(
                        $measurement->created_at?->getTimestamp() ?? $measurement->timestamp
                    );

                    return [
                        'timestamp' => $measurement->timestamp,
                        'temperature' => $measurement->data->temperature,
                        'humidity' => $measurement->data->humidity,
                        'pressure' => $measurement->data->pressure,
                        'at' => $receivedAt->format('j. n. Y H:i'),
                        'ago' => $this->ago($receivedAt),
                        't' => round($measurement->data->temperature / 100, 2),
                        'h' => round($measurement->data->humidity / 100, 2),
                        'p' => $this->seaLevelHpa($measurement->data),
                    ];
                })
                ->all()
        );
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
     * When the station last measured anything, across the whole table rather
     * than the window - zooming in must not make the station look silent.
     *
     * The station's own stamp, not the row's `created_at`: a lost link buffers
     * readings on the device and delivers them late, so the arrival of the
     * newest row says nothing about how long ago the station last read its
     * sensor. That is the question this answers, and the indicator beside it.
     *
     * This is a real instant, unlike the stamps in the chart payload, so it is
     * the only date here that may be measured against now().
     */
    #[Computed]
    public function lastMeasurement(): ?CarbonInterface
    {
        $timestamp = Measurement::query()->max('timestamp');

        return $timestamp === null
            ? null
            : Date::createFromTimestamp((int) $timestamp, self::DISPLAY_TIMEZONE);
    }

    #[Computed]
    public function measuredAt(): ?string
    {
        return $this->lastMeasurement?->format('j. n. Y H:i');
    }

    #[Computed]
    public function measuredAgo(): ?string
    {
        return $this->lastMeasurement === null
            ? null
            : $this->ago($this->lastMeasurement);
    }

    /** Nothing for three slots running: treat the station as off the air. */
    #[Computed]
    public function isSilent(): bool
    {
        return $this->lastMeasurement === null
            || $this->lastMeasurement->getTimestamp() < now()->getTimestamp() - self::SILENT_AFTER_SECONDS;
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
     * Every event ever done to the station, for the charts to mark.
     *
     * Not bounded to the window: there are a handful of these over the life of
     * the station, and a mark outside the axis simply is not drawn. Each entry
     * is `[wall-clock ms, title, colour]`, the stamp shifted the same way as
     * the readings so the mark lands on the axis where the readings do. The
     * colour is passed through as entered, null included - the chart owns the
     * fallback, since it owns the palette.
     *
     * @return list<array{0: int, 1: string, 2: ?string}>
     */
    #[Computed]
    public function stationEvents(): array
    {
        return array_values(
            StationEvent::query()
                ->oldest('occurred_at')
                ->get()
                ->map(fn (StationEvent $event): array => [
                    $this->wallClockMs($event->occurred_at),
                    $event->title,
                    $event->color,
                ])
                ->all()
        );
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

    /**
     * Relative wording, which is what tells you at a glance if it is late.
     *
     * A reading can be stamped slightly ahead of the server: the station syncs
     * NTP once and its clock then free-runs on the RTC oscillator through
     * every deep sleep, which drifts. "4 minutes from now" reads as a broken
     * page, so anything not yet past is reported as having just landed.
     */
    private function ago(CarbonInterface $moment): string
    {
        return $moment->getTimestamp() > now()->getTimestamp()
            ? 'just now'
            : $moment->diffForHumans();
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
     * Keep the first reading of every bucket and drop the rest.
     *
     * Grouping, not `timestamp % bucket < STEP_SECONDS`: the station stamps an
     * upload when it wakes rather than on the slot, so its stamps sit minutes
     * off every multiple of the step and a phase window only lands on a row
     * while that drift stays small. Grouping asks for the bucket's own first
     * row instead, whatever time it carries.
     *
     * Nothing is averaged - every plotted point remains a stored record.
     *
     * @param  Builder<Measurement>  $query
     * @param  array{0: int, 1: int}|null  $window  Bound the subquery to the same range as the outer one.
     * @return Builder<Measurement>
     */
    private function firstPerBucket(Builder $query, int $bucketSeconds, ?array $window = null): Builder
    {
        return $query->whereIn('timestamp', function (QueryBuilder $bucket) use ($bucketSeconds, $window): void {
            $bucket->selectRaw('MIN(timestamp)')
                ->from('measurements')
                ->groupByRaw('timestamp / ?', [$bucketSeconds]);

            if ($window !== null) {
                $bucket->whereBetween('timestamp', $window);
            }
        });
    }

    /**
     * Station pressure reduced to sea level, in hPa, as everything here shows it.
     *
     * Two decimals is the sensor's own resolution - it reports whole pascals -
     * and the chart needs all of it. A day of weather moves the line by a
     * couple of hPa, the strip's axis scales to whatever it finds, so rounding
     * to tenths drew the curve as a staircase a fifteenth of the strip high.
     * The readouts and the payload tail print a tenth of what this returns.
     */
    private function seaLevelHpa(MeasurementData $data): float
    {
        return SeaLevelPressure::reduce($data, self::ALTITUDE_METRES)->hectopascals(2);
    }

    /**
     * Dew point in °C, at the resolution the temperature beside it is shown.
     *
     * Null where there is none (see DewPoint::of), which the chart draws as a
     * gap in the line.
     */
    private function dewPointCelsius(MeasurementData $data): ?float
    {
        return DewPoint::of($data)?->celsius(2);
    }

    /**
     * Chart rows for a set of measurements, oldest first.
     *
     * Columns hold the raw protocol units (see ProtocolVersion::V1):
     * temperature and humidity in hundredths, pressure in pascals. The chart
     * wants °C, % and hPa reduced to sea level, plus the dew point in °C,
     * which the station does not send and is derived here.
     *
     * @param  Collection<int, Measurement>  $measurements
     * @return list<array{0: int, 1: float, 2: float, 3: float, 4: ?float, 5: int}>
     */
    private function plot(Collection $measurements): array
    {
        return array_values(
            $measurements
                ->map(fn (Measurement $measurement): array => [
                    $this->wallClockMs($measurement->timestamp),
                    round($measurement->data->temperature / 100, 2),
                    round($measurement->data->humidity / 100, 2),
                    $this->seaLevelHpa($measurement->data),
                    $this->dewPointCelsius($measurement->data),
                    $measurement->timestamp,
                ])
                ->all()
        );
    }

    /**
     * Hero readout figures for one metric.
     *
     * @return array{now: float, delta: float, dayMin: float, dayMax: float}
     */
    private function figures(string $field): array
    {
        $day = array_column($this->lastDay, $field);

        if ($day === []) {
            throw new UnexpectedValueException("No readings to summarise for [{$field}].");
        }

        $now = end($day);

        return [
            'now' => $now,
            // vs. one hour ago, or the oldest point we have if the window is shorter
            'delta' => $now - (float) $day[max(0, count($day) - 7)],
            'dayMin' => min($day),
            'dayMax' => max($day),
        ];
    }
}

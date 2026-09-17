<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\ChartRange;
use App\Models\Measurement;
use App\Models\Sensor;
use App\Models\StationEvent;
use App\Models\StationReport;
use App\ValueObject\DewPoint;
use App\ValueObject\MeasurementData;
use App\ValueObject\MeasurementDataV1;
use App\ValueObject\SeaLevelPressure;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
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
 * @property-read list<BucketRow> $readings
 * @property-read list<ReadingRow> $overview
 * @property-read array{from: int, to: int} $windowMs
 * @property-read bool $hasReadings
 * @property-read int $recordCount
 * @property-read list<DayRow> $lastDay
 * @property-read array<string, array{now: float, delta: float, dayMin: float, dayMax: float}> $metrics
 * @property-read list<array{timestamp: int, packet: array<string, int>, at: string, ago: string, t: float, h: float, p: float}> $recentTransmissions
 * @property-read array{lat: float, lng: float, radius: int} $approximateLocation
 * @property-read array{firmware: string, resetReason: string, uptime: string, network: string, ssid: string, ip: string, rssi: int, switches: int, heapFree: int, heapMin: int, buffered: int, uploadFailures: int, at: string, ago: string}|null $stationReport
 * @property-read int $currentYear
 * @property-read CarbonInterface|null $lastMeasurement
 * @property-read string|null $measuredAt
 * @property-read string|null $measuredAgo
 * @property-read bool $isSilent
 * @property-read array{from: string, to: string} $window
 * @property-read bool $isZoomed
 * @property-read list<string> $hiddenChannels
 * @property-read list<array{0: int, 1: string, 2: ?string}> $stationEvents
 * @property-read Collection<int, Sensor> $sensors
 * @property-read Sensor|null $selectedSensor
 * @property-read bool $hasSensorChoice
 *
 * Chart rows are positional: the payload is JSON and a month runs to 720 of
 * them. A reading row is a stored record; a bucket row is a slot, and holds
 * nulls where the station missed it. A bucket row goes on to carry the
 * extremes of the samples inside it, temperature, humidity and pressure in
 * turn, each as a min-max pair.
 *
 * @phpstan-type ReadingRow array{0: int, 1: float, 2: float, 3: float, 4: ?float, 5: int}
 * @phpstan-type BucketRow array{0: int, 1: ?float, 2: ?float, 3: ?float, 4: ?float, 5: int, 6: ?float, 7: ?float, 8: ?float, 9: ?float, 10: ?float, 11: ?float}
 * @phpstan-type DayRow array{t: float, h: float, p: float, tMin: float, tMax: float, hMin: float, hMax: float, pMin: float, pMax: float}
 * @phpstan-type Bucket object{bucket: int, t_avg: ?string, h_avg: ?string, p_avg: ?string, t_min: ?string, t_max: ?string, h_min: ?string, h_max: ?string, p_min: ?string, p_max: ?string}
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

    /**
     * The widest window the charts draw.
     *
     * A strip is about a thousand pixels across. A month of hourly means
     * gives each day some thirty of them, enough to read its rise and fall;
     * a year would give it three, which is noise, and averaging it into
     * wider buckets flattens the very swing a weather chart is for. The
     * navigator still spans the whole record for finding a month in it.
     */
    private const int MAX_SPAN_SECONDS = 2592000;

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
     * Which sensor the page reads, by slug, or null for the first registered.
     *
     * The slug rather than the id: it is the firmware's own name made safe
     * for a URL, so the link reads `?sensor=sensor-001` and survives a
     * reseed. Everything below the top bar - readouts, charts, events, the
     * payload tail - is that one sensor's. A slug that matches nothing falls
     * back the same way, so a stale link still opens on a station.
     */
    #[Url]
    public ?string $sensor = null;

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
        $this->normaliseSensor();
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

    /** The picker sends whatever slug the option carried; it may be gone by now. */
    public function updatedSensor(): void
    {
        $this->normaliseSensor();
    }

    /**
     * Every sensor that ever uploaded, oldest registration first.
     *
     * That order is what makes the first one the default: the original
     * station keeps the front page when a second one is added.
     *
     * @return Collection<int, Sensor>
     */
    #[Computed]
    public function sensors(): Collection
    {
        return Sensor::query()->orderBy('id')->get();
    }

    /** The sensor whose record the page shows; null only while none has uploaded. */
    #[Computed]
    public function selectedSensor(): ?Sensor
    {
        return $this->sensors->firstWhere('slug', $this->sensor) ?? $this->sensors->first();
    }

    /** One sensor is nothing to choose between, so the picker waits for a second. */
    #[Computed]
    public function hasSensorChoice(): bool
    {
        return $this->sensors->count() >= 2;
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
     * The window averaged into buckets, oldest first, as compact rows.
     *
     * Arrays rather than keyed objects because this is the chart payload and
     * a month runs to seven hundred rows. Each row is
     * `[wall-clock ms, °C, %, hPa, dew point °C, bucket epoch seconds]`
     * followed by the extremes of the samples in the bucket, `°C min, °C max,
     * % min, % max, hPa min, hPa max` - the band the chart draws behind each
     * mean.
     *
     * The bucket width follows the span on screen (ChartRange::bucketSeconds)
     * and every bucket in the window is a row, whether a reading landed in it
     * or not: an empty one carries nulls, which the chart draws as a hole in
     * the line. That is what keeps the axis honest about an outage rather than
     * joining the readings either side of it. A window with no readings at
     * all is the empty list, not a row of holes.
     *
     * The first element is shifted to Czech local time and the chart is told
     * to read it as UTC, which is what makes the axis and tooltip read local
     * without depending on the viewer's own clock. It is therefore not a real
     * instant - the sixth element is, and that is what a zoom sends back.
     *
     * @return list<BucketRow>
     */
    #[Computed]
    public function readings(): array
    {
        $step = ChartRange::forSpan($this->spanSeconds())->bucketSeconds();

        $buckets = $this->buckets($step, $this->windowFrom(), $this->windowTo());

        if (! $buckets->contains(fn (object $bucket): bool => $bucket->t_avg !== null)) {
            return [];
        }

        return array_values($buckets->map(fn (object $bucket): array => $this->plotBucket($bucket))->all());
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
     * @return list<ReadingRow>
     */
    #[Computed]
    public function overview(): array
    {
        $total = $this->measurements()->count();

        return $this->plot(
            $this->measurements()
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
     * How many readings the station stored inside the window.
     *
     * Counted in the table rather than off the payload: that is one row per
     * slot, holes included, and would report a month the station slept
     * through as seven hundred records.
     */
    #[Computed]
    public function recordCount(): int
    {
        return $this->measurements()
            ->whereBetween('timestamp', [$this->windowFrom(), $this->windowTo()])
            ->count();
    }

    /**
     * The newest bucket on screen that holds a reading, as hero figures.
     *
     * The payload ends on the window's last slot, which is a hole whenever
     * the station is a few minutes late, so the last row is not the last
     * reading.
     *
     * @return DayRow|null
     */
    private function newestPlottedReading(): ?array
    {
        foreach (array_reverse($this->readings) as $row) {
            if ($row[1] !== null && $row[2] !== null && $row[3] !== null) {
                return [
                    't' => $row[1],
                    'h' => $row[2],
                    'p' => $row[3],
                    'tMin' => $row[6] ?? $row[1],
                    'tMax' => $row[7] ?? $row[1],
                    'hMin' => $row[8] ?? $row[2],
                    'hMax' => $row[9] ?? $row[2],
                    'pMin' => $row[10] ?? $row[3],
                    'pMax' => $row[11] ?? $row[3],
                ];
            }
        }

        return null;
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
     * Falls back to the newest plotted bucket when the station has been quiet
     * for over a day, so the cards still show its last state - a mean over
     * that bucket's slot on a window wider than a day, which is as close to
     * the last reading as the payload gets.
     *
     * Each row carries the entry's extremes beside its value: on V2 those
     * are the coldest and warmest sample of the window, on V1 the reading
     * itself, so the day's minimum and maximum read what the sensor saw
     * rather than what the means smoothed over.
     *
     * @return list<DayRow>
     */
    #[Computed]
    public function lastDay(): array
    {
        $day = array_values(
            $this->measurements()
                ->where('timestamp', '>=', now()->subDay()->getTimestamp())
                ->orderBy('timestamp')
                ->get()
                ->map(fn (Measurement $measurement): array => [
                    't' => round($measurement->data->temperature / 100, 2),
                    'h' => round($measurement->data->humidity / 100, 2),
                    'p' => $this->seaLevelHpa($measurement->data),
                    'tMin' => round($measurement->data->temperatureMin / 100, 2),
                    'tMax' => round($measurement->data->temperatureMax / 100, 2),
                    'hMin' => round($measurement->data->humidityMin / 100, 2),
                    'hMax' => round($measurement->data->humidityMax / 100, 2),
                    'pMin' => $this->seaLevelHpa($this->withPressure($measurement->data, $measurement->data->pressureMin)),
                    'pMax' => $this->seaLevelHpa($this->withPressure($measurement->data, $measurement->data->pressureMax)),
                ])
                ->all()
        );

        if ($day !== []) {
            return $day;
        }

        $newest = $this->newestPlottedReading();

        return $newest === null ? [] : [$newest];
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
     * last spring does not empty the tail. Each row keeps the entry's fixed
     * point integers under the protocol's own keys - that is what the endpoint
     * received and what the blob holds, whichever version sent it - with the
     * converted figures alongside for the second column, where pressure is
     * the reduced one the rest of the page shows.
     *
     * The date is `created_at`, when the row reached the server - the reading's
     * own stamp is already printed in the JSON beside it, and repeating it as
     * a formatted date would say the same thing twice. Arrival is the other
     * half of the story: a buffered batch lands minutes or hours after it was
     * measured, and this is the only place that shows the gap.
     *
     * @return list<array{timestamp: int, packet: array<string, int>, at: string, ago: string, t: float, h: float, p: float}>
     */
    #[Computed]
    public function recentTransmissions(): array
    {
        return array_values(
            $this->measurements()
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
                        'packet' => $measurement->data->jsonSerialize(),
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
     * The board's last word about itself: what the newest upload from the
     * selected sensor carried in its `station` object, or null when the
     * firmware has not reported yet.
     *
     * The date is arrival, like the payload tail's - the report describes the
     * board at the moment it uploaded, so the two are the same instant.
     *
     * @return array{firmware: string, resetReason: string, uptime: string, network: string, ssid: string, ip: string, rssi: int, switches: int, heapFree: int, heapMin: int, buffered: int, uploadFailures: int, at: string, ago: string}|null
     */
    #[Computed]
    public function stationReport(): ?array
    {
        $report = StationReport::query()
            ->where('sensor_id', $this->selectedSensor?->id)
            ->latest('id')
            ->first();

        if ($report === null) {
            return null;
        }

        $data = $report->data;
        $receivedAt = $this->localise($report->created_at?->getTimestamp() ?? 0);

        return [
            'firmware' => (string) $data['firmware'],
            'resetReason' => (string) $data['reset_reason'],
            'uptime' => $this->duration((int) $data['uptime']),
            'network' => (int) $data['wifi_network'] === 0 ? 'primary' : 'backup',
            'ssid' => (string) ($data['ssid'] ?? ''),
            'ip' => (string) ($data['ip'] ?? ''),
            'rssi' => (int) $data['rssi'],
            'switches' => (int) $data['wifi_switches'],
            'heapFree' => (int) $data['heap_free'],
            'heapMin' => (int) $data['heap_min'],
            'buffered' => (int) $data['buffered'],
            'uploadFailures' => (int) $data['upload_failures'],
            'at' => $receivedAt->format('j. n. Y H:i'),
            'ago' => $this->ago($receivedAt),
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
        $timestamp = $this->measurements()->max('timestamp');

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

    /**
     * The selected sensor's readings, and nothing else's.
     *
     * With no sensor at all the id is null and the comparison matches no row,
     * which is the empty page the template already draws.
     *
     * @return Builder<Measurement>
     */
    private function measurements(): Builder
    {
        return Measurement::query()->where('sensor_id', $this->selectedSensor?->id);
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
     * Every event ever done to the selected sensor, for the charts to mark.
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
                ->where('sensor_id', $this->selectedSensor?->id)
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
     * Pin the property to the sensor actually shown.
     *
     * The picker is bound to the property, so it must hold a real slug even
     * when the page opened without one - a native select given nothing it
     * knows shows a blank. Livewire keeps the initial value out of the query
     * string, so the default stays a clean URL.
     */
    private function normaliseSensor(): void
    {
        unset($this->selectedSensor);

        $this->sensor = $this->selectedSensor?->slug;
    }

    /**
     * Both ends arrive from the query string, where anything can be typed, and
     * from a drag that may have been a stray click. Keep them ordered, wide
     * enough to draw, no wider than a month, and out of the future.
     *
     * Too wide is clipped from the front: the newer end is the one a reader
     * dragged to, or typed, on purpose.
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
        $this->from = max(0, $this->to - self::MAX_SPAN_SECONDS, min($this->from, $this->to - self::MIN_SPAN_SECONDS));
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

    /** Seconds as the largest two units that fit: "3 d 4 h", "4 h 12 min", "12 min 5 s". */
    private function duration(int $seconds): string
    {
        $days = intdiv($seconds, 86_400);
        $hours = intdiv($seconds % 86_400, 3_600);
        $minutes = intdiv($seconds % 3_600, 60);

        return match (true) {
            $days > 0 => "{$days} d {$hours} h",
            $hours > 0 => "{$hours} h {$minutes} min",
            default => "{$minutes} min ".($seconds % 60).' s',
        };
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
     * The navigator's thinning, and only the navigator's: it exists to show
     * where the window sits, so a real reading per bucket is enough and the
     * averaging the strips do (see buckets()) would be work for a few pixels.
     *
     * Grouping, not `timestamp % bucket < STEP_SECONDS`: the station stamps an
     * upload when it wakes rather than on the slot, so its stamps sit minutes
     * off every multiple of the step and a phase window only lands on a row
     * while that drift stays small. Grouping asks for the bucket's own first
     * row instead, whatever time it carries.
     *
     * @param  Builder<Measurement>  $query
     * @return Builder<Measurement>
     */
    private function firstPerBucket(Builder $query, int $bucketSeconds): Builder
    {
        return $query->whereIn('timestamp', function (QueryBuilder $bucket) use ($bucketSeconds): void {
            // Scoped to the sensor as well: another station's first row of a
            // bucket is an earlier stamp this one never sent.
            $bucket->selectRaw('MIN(timestamp)')
                ->from('measurements')
                ->where('sensor_id', $this->selectedSensor?->id)
                ->groupByRaw('timestamp / ?', [$bucketSeconds]);
        });
    }

    /**
     * Every bucket between two instants, each averaged over its readings.
     *
     * Done in SQL, and PostgreSQL's SQL: `generate_series` lays out one slot
     * per bucket across the window and the readings are averaged onto it with
     * a left join, so a slot the station missed comes back as a row of nulls
     * rather than not at all. The buckets divide the epoch, not the local
     * day, which is why they never move with daylight saving.
     *
     * The blob is read with the protocol keys directly. Aggregating cannot go
     * through ProtocolVersion::hydrate() row by row, so a later protocol that
     * renames a field has to teach this query about it as well. The mean is
     * read under the V1 keys, which V2 keeps for its window mean; the
     * extremes fall back to the value itself where an entry has none (V1),
     * which is the same statement the value objects make - a single reading
     * is its own minimum and maximum. The bucket's band is therefore the
     * spread of every sample inside it, whichever version stored them.
     *
     * @return SupportCollection<int, Bucket>
     */
    private function buckets(int $step, int $from, int $to): SupportCollection
    {
        $first = intdiv($from, $step) * $step;
        $last = intdiv($to, $step) * $step;

        $readings = DB::table('measurements')
            ->selectRaw('(timestamp / ?::int) * ?::int AS bucket', [$step, $step])
            ->where('sensor_id', $this->selectedSensor?->id)
            // The whole of the first and last buckets, so an edge bucket is
            // the same average whichever instant inside it the window opened on.
            ->whereBetween('timestamp', [$first, $last + $step - 1])
            ->groupByRaw('1');

        foreach (['t' => 'temperature', 'h' => 'humidity', 'p' => 'pressure'] as $column => $field) {
            $readings
                ->selectRaw("AVG((data->>'{$field}')::int) AS {$column}_avg")
                ->selectRaw("MIN(COALESCE(data->>'{$field}_min', data->>'{$field}')::int) AS {$column}_min")
                ->selectRaw("MAX(COALESCE(data->>'{$field}_max', data->>'{$field}')::int) AS {$column}_max");
        }

        /** @var SupportCollection<int, Bucket> $buckets */
        $buckets = DB::query()
            ->fromRaw('generate_series(?::int, ?::int, ?::int) AS slot (bucket)', [$first, $last, $step])
            ->leftJoinSub($readings, 'reading', 'reading.bucket', '=', 'slot.bucket')
            ->select('slot.bucket')
            ->addSelect(['t_avg', 'h_avg', 'p_avg', 't_min', 't_max', 'h_min', 'h_max', 'p_min', 'p_max'])
            ->orderBy('slot.bucket')
            ->get();

        return $buckets;
    }

    /**
     * A chart row for one bucket, or a row of holes for an empty one.
     *
     * The averages are turned back into a measurement in the protocol's own
     * units, so the pressure reduction and the dew point run through the same
     * code as a single reading. The pressure extremes are reduced with the
     * bucket's mean temperature: the reduction needs one, and the sample that
     * read the lowest pressure did not record its own.
     *
     * @param  Bucket  $bucket
     * @return BucketRow
     */
    private function plotBucket(object $bucket): array
    {
        $time = $this->wallClockMs($bucket->bucket);

        if ($bucket->t_avg === null || $bucket->h_avg === null || $bucket->p_avg === null) {
            return [$time, null, null, null, null, $bucket->bucket, null, null, null, null, null, null];
        }

        $mean = new MeasurementDataV1(
            temperature: (int) round((float) $bucket->t_avg),
            humidity: (int) round((float) $bucket->h_avg),
            pressure: (int) round((float) $bucket->p_avg),
        );

        return [
            $time,
            round((float) $bucket->t_avg / 100, 2),
            round((float) $bucket->h_avg / 100, 2),
            $this->seaLevelHpa($mean),
            $this->dewPointCelsius($mean),
            $bucket->bucket,
            round((float) $bucket->t_min / 100, 2),
            round((float) $bucket->t_max / 100, 2),
            round((float) $bucket->h_min / 100, 2),
            round((float) $bucket->h_max / 100, 2),
            $this->seaLevelHpa($this->withPressure($mean, (int) $bucket->p_min)),
            $this->seaLevelHpa($this->withPressure($mean, (int) $bucket->p_max)),
        ];
    }

    /**
     * The same entry with another pressure, for reducing an extreme.
     *
     * SeaLevelPressure reads the temperature off the entry it is given, and
     * the extremes were read by samples that kept no temperature of their
     * own - the entry's is the nearest thing to it.
     */
    private function withPressure(MeasurementData $data, int $pressure): MeasurementData
    {
        return new MeasurementDataV1(
            temperature: $data->temperature,
            humidity: $data->humidity,
            pressure: $pressure,
        );
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
     * @return list<ReadingRow>
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
     * The day's minimum and maximum come off the entries' extremes, not their
     * values: a V2 entry is a ten-minute mean, and the coldest sample of the
     * night sits below the coldest mean.
     *
     * @return array{now: float, delta: float, dayMin: float, dayMax: float}
     */
    private function figures(string $field): array
    {
        $day = array_column($this->lastDay, $field);
        $lows = array_column($this->lastDay, "{$field}Min");
        $highs = array_column($this->lastDay, "{$field}Max");

        if ($day === [] || $lows === [] || $highs === []) {
            throw new UnexpectedValueException("No readings to summarise for [{$field}].");
        }

        $now = end($day);

        return [
            'now' => $now,
            // vs. one hour ago, or the oldest point we have if the window is shorter
            'delta' => $now - (float) $day[max(0, count($day) - 7)],
            'dayMin' => min($lows),
            'dayMax' => max($highs),
        ];
    }
}

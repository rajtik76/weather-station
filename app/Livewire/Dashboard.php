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
use App\ValueObject\NoiseWindow;
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
 * `#[Computed]` methods are declared as properties for Larastan.
 *
 * @property-read list<BucketRow> $readings
 * @property-read list<NoiseRow> $noise
 * @property-read list<ReadingRow> $overview
 * @property-read array{from: int, to: int} $windowMs
 * @property-read bool $hasReadings
 * @property-read int $recordCount
 * @property-read list<DayRow> $lastDay
 * @property-read array<string, array{now: float, delta: float, dayMin: float, dayMax: float}> $metrics
 * @property-read list<array{timestamp: int, packet: array<string, int|array<string, int|list<int>>>, at: string, ago: string, t: float, h: float, p: float}> $recentTransmissions
 * @property-read array{lat: float, lng: float, radius: int} $approximateLocation
 * @property-read array{firmware: string, board: string|null, resetReason: string, uptime: string, network: string, rssi: int, switches: int, heapFree: int, heapMin: int, buffered: int, uploadFailures: int, clockDrift: string|null, clockDriftWorst: string|null, clockSynced: string|null, at: string, ago: string}|null $stationReport
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
 * Chart rows are positional arrays to keep the JSON payload small. A bucket
 * row is `[wall-clock ms, t, h, p, dew point, epoch, tMin, tMax, hMin, hMax, pMin, pMax]`
 * with nulls for an empty slot; a reading row stops after the epoch.
 *
 * @phpstan-type ReadingRow array{0: int, 1: float, 2: float, 3: float, 4: ?float, 5: int}
 * @phpstan-type BucketRow array{0: int, 1: ?float, 2: ?float, 3: ?float, 4: ?float, 5: int, 6: ?float, 7: ?float, 8: ?float, 9: ?float, 10: ?float, 11: ?float}
 * @phpstan-type DayRow array{t: float, h: float, p: float, tMin: float, tMax: float, hMin: float, hMax: float, pMin: float, pMax: float}
 * A noise row is `[wall-clock ms, epoch, LAeq, LA10, LA90, LAmax, 26 bands]` in dB,
 * nulls for a slot without noise.
 * @phpstan-type NoiseRow list<int|float|null>
 * @phpstan-type NoiseBucket object{bucket: int, laeq: ?string, la10: ?string, la90: ?string, lamax: ?string, bands: ?string}
 * @phpstan-type Bucket object{bucket: int, t_avg: ?string, h_avg: ?string, p_avg: ?string, t_min: ?string, t_max: ?string, h_min: ?string, h_max: ?string, p_min: ?string, p_max: ?string}
 */
#[Title('Station Log')]
class Dashboard extends Component
{
    /** Reporting interval of the station. */
    private const int STEP_SECONDS = 600;

    /** Stored stamps are UTC; only the presentation shifts. */
    private const string DISPLAY_TIMEZONE = 'Europe/Prague';

    private const float LATITUDE = 49.733242;

    private const float LONGITUDE = 13.399911;

    /** Station height for the sea-level reduction; the stored reading stays as sent. */
    private const float ALTITUDE_METRES = 345.0;

    private const int LOCATION_RADIUS_METRES = 800;

    private const int SILENT_AFTER_SECONDS = 3 * self::STEP_SECONDS;

    /** Navigator thinning: one reading per bucket once the record is large. */
    private const int OVERVIEW_BUCKET_SECONDS = 21600;

    private const int OVERVIEW_UNTHINNED_ROWS = 1500;

    private const ChartRange DEFAULT_WINDOW = ChartRange::Week;

    /** Fewer points than this would not make a line. */
    private const int MIN_SPAN_SECONDS = 4 * self::STEP_SECONDS;

    /**
     * A month. A strip is ~1000 px wide; hourly means over a month still
     * show a day's swing, anything wider averages it away. The navigator
     * spans the whole record regardless.
     */
    private const int MAX_SPAN_SECONDS = 2592000;

    private const int RECENT_TRANSMISSIONS = 3;

    /** How long a window's microphone listened; the weight of its noise levels. */
    private const string NOISE_SECONDS = "(data->'noise'->>'seconds')::float8";

    /** Zoomed window as UTC epochs, null to follow the default. Instants, not preset steps, so a drag can land anywhere. */
    #[Url]
    public ?int $from = null;

    #[Url]
    public ?int $to = null;

    /** Sensor slug, null for the first registered. Slug rather than id so the link survives a reseed. */
    #[Url]
    public ?string $sensor = null;

    /**
     * Which lines the shared strip draws. Locked so the last-channel guard
     * in toggleChannel() cannot be bypassed with `$wire.set()`.
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

    public function updatedSensor(): void
    {
        $this->normaliseSensor();
    }

    /**
     * Oldest registration first, so the original station stays the default.
     *
     * @return Collection<int, Sensor>
     */
    #[Computed]
    public function sensors(): Collection
    {
        return Sensor::query()->orderBy('id')->get();
    }

    #[Computed]
    public function selectedSensor(): ?Sensor
    {
        return $this->sensors->firstWhere('slug', $this->sensor) ?? $this->sensors->first();
    }

    #[Computed]
    public function hasSensorChoice(): bool
    {
        return $this->sensors->count() >= 2;
    }

    /** The last line on stays on; the template disables that switch too, this holds when it does not. */
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

    public function isLastChannel(string $channel): bool
    {
        return ($this->channels[$channel] ?? false)
            && count(array_filter($this->channels)) === 1;
    }

    /**
     * Read against the defaults, so a key missing or added client-side changes nothing.
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
     * The window as buckets, oldest first. Every slot is a row; an empty one
     * carries nulls so an outage draws as a gap, not a line across it. A
     * window with no readings at all is `[]`.
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
     * Protocol 3's noise over the same buckets as the readings. A window
     * that holds no noise at all (older rows, a dead microphone) is `[]`,
     * and the noise strips do not render.
     *
     * @return list<NoiseRow>
     */
    #[Computed]
    public function noise(): array
    {
        $hasNoise = $this->measurements()
            ->whereBetween('timestamp', [$this->windowFrom(), $this->windowTo()])
            ->whereRaw("data->'noise' IS NOT NULL")
            ->exists();

        if (! $hasNoise) {
            return [];
        }

        $step = ChartRange::forSpan($this->spanSeconds())->bucketSeconds();

        $buckets = $this->noiseBuckets($step, $this->windowFrom(), $this->windowTo());

        return array_values($buckets->map(fn (object $bucket): array => $this->plotNoise($bucket))->all());
    }

    /**
     * The whole record for the navigator, thinned to one reading per six
     * hours once it is large enough to need it.
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
     * The window in the navigator's own axis units (see wallClockMs).
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

    #[Computed]
    public function hasReadings(): bool
    {
        return $this->readings !== [];
    }

    /** Counted in the table, not off the payload, which has a row per slot whether filled or not. */
    #[Computed]
    public function recordCount(): int
    {
        return $this->measurements()
            ->whereBetween('timestamp', [$this->windowFrom(), $this->windowTo()])
            ->count();
    }

    /**
     * The newest non-empty bucket on screen; the last row is usually a hole.
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
     * The trailing 24 hours for the readouts. Queried apart from `readings`
     * so the zoom does not change what "24 h" means. Falls back to the newest
     * plotted bucket when the station has been quiet for over a day.
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
     * The newest rows across the whole table, not the window. The date is
     * `created_at`: the reading's own stamp is in the JSON beside it, and a
     * buffered batch arrives long after it was measured.
     *
     * @return list<array{timestamp: int, packet: array<string, int|array<string, int|list<int>>>, at: string, ago: string, t: float, h: float, p: float}>
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
                    // Rows written before the column existed.
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
     * The newest `station` object of the selected sensor. SSID and IP stay
     * off the page: it is public.
     *
     * @return array{firmware: string, board: string|null, resetReason: string, uptime: string, network: string, rssi: int, switches: int, heapFree: int, heapMin: int, buffered: int, uploadFailures: int, clockDrift: string|null, clockDriftWorst: string|null, clockSynced: string|null, at: string, ago: string}|null
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
            // Null before firmware 2.3, which is when the board began reporting it.
            'board' => isset($data['board']) ? (string) $data['board'] : null,
            'resetReason' => (string) $data['reset_reason'],
            'uptime' => $this->duration((int) $data['uptime']),
            'network' => (int) $data['wifi_network'] === 0 ? 'primary' : 'backup',
            'rssi' => (int) $data['rssi'],
            'switches' => (int) $data['wifi_switches'],
            'heapFree' => (int) $data['heap_free'],
            'heapMin' => (int) $data['heap_min'],
            'buffered' => (int) $data['buffered'],
            'uploadFailures' => (int) $data['upload_failures'],
            ...$this->clockDrift($data),
            'at' => $receivedAt->format('j. n. Y H:i'),
            'ago' => $this->ago($receivedAt),
        ];
    }

    /**
     * Nulls until the board has re-synced once since boot: the boot sync steps
     * from 1970 and says nothing about the crystal.
     *
     * @param  array<string, mixed>  $data
     * @return array{clockDrift: string|null, clockDriftWorst: string|null, clockSynced: string|null}
     */
    private function clockDrift(array $data): array
    {
        $overSeconds = (int) ($data['clock_step_over_s'] ?? 0);
        $syncedAt = (int) ($data['clock_synced_at'] ?? 0);

        if ($overSeconds <= 0 || $syncedAt <= 0) {
            return ['clockDrift' => null, 'clockDriftWorst' => null, 'clockSynced' => null];
        }

        return [
            'clockDrift' => $this->signedMilliseconds((int) $data['clock_step_ms']).' in '.$this->duration($overSeconds),
            'clockDriftWorst' => $this->signedMilliseconds((int) ($data['clock_step_max_ms'] ?? $data['clock_step_ms'])),
            'clockSynced' => $this->localise($syncedAt)->format('H:i'),
        ];
    }

    /** "+812 ms", "-1 204 ms", "0 ms". */
    private function signedMilliseconds(int $milliseconds): string
    {
        $sign = match (true) {
            $milliseconds > 0 => '+',
            $milliseconds < 0 => '-',
            default => '',
        };

        return $sign.number_format(abs($milliseconds), 0, ',', ' ').' ms';
    }

    /**
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

    #[Computed]
    public function currentYear(): int
    {
        return now(self::DISPLAY_TIMEZONE)->year;
    }

    /**
     * Across the whole table, not the window. The station's own stamp, not
     * `created_at`: a buffered batch arrives late and says nothing about when
     * the sensor was last read.
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

    #[Computed]
    public function isSilent(): bool
    {
        return $this->lastMeasurement === null
            || $this->lastMeasurement->getTimestamp() < now()->getTimestamp() - self::SILENT_AFTER_SECONDS;
    }

    /**
     * With no sensor the id is null and nothing matches, which is the empty page.
     *
     * @return Builder<Measurement>
     */
    private function measurements(): Builder
    {
        return Measurement::query()->where('sensor_id', $this->selectedSensor?->id);
    }

    private function windowFrom(): int
    {
        return $this->from ?? now()->getTimestamp() - self::DEFAULT_WINDOW->durationSeconds();
    }

    private function windowTo(): int
    {
        return $this->to ?? now()->getTimestamp();
    }

    private function spanSeconds(): int
    {
        return $this->windowTo() - $this->windowFrom();
    }

    /**
     * `[wall-clock ms, title, colour]` per event, all of them: there are a
     * handful and a mark outside the axis is not drawn. Colour null means the
     * chart picks.
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

    /** The picker is bound to the property, so it must hold a real slug or the select shows blank. */
    private function normaliseSensor(): void
    {
        unset($this->selectedSensor);

        $this->sensor = $this->selectedSensor?->slug;
    }

    /**
     * Ordered, at least MIN_SPAN, at most MAX_SPAN, not in the future. Too
     * wide is clipped from the front: the newer end is the one chosen.
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

    /** The station's clock drifts and may stamp ahead of the server; "4 minutes from now" reads as broken. */
    private function ago(CarbonInterface $moment): string
    {
        return $moment->getTimestamp() > now()->getTimestamp()
            ? 'just now'
            : $moment->diffForHumans();
    }

    private function localise(int $timestamp): CarbonInterface
    {
        return Date::createFromTimestamp($timestamp, self::DISPLAY_TIMEZONE);
    }

    /** "3 d 4 h", "4 h 12 min", "12 min 5 s". */
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
     * Epoch as milliseconds of local wall-clock time. The chart reads its
     * axis as UTC, so this is what makes ticks and tooltips print local time
     * whatever the viewer's clock. Not an instant: never compare with now()
     * or convert again. Rows carry the real epoch beside it.
     */
    private function wallClockMs(int $timestamp): int
    {
        return ($timestamp + $this->localise($timestamp)->utcOffset() * 60) * 1000;
    }

    /**
     * First reading of every bucket. Grouping, not `timestamp % bucket <
     * STEP`: the station's stamps sit minutes off the slot and drift, so a
     * phase test would miss rows.
     *
     * @param  Builder<Measurement>  $query
     * @return Builder<Measurement>
     */
    private function firstPerBucket(Builder $query, int $bucketSeconds): Builder
    {
        return $query->whereIn('timestamp', function (QueryBuilder $bucket) use ($bucketSeconds): void {
            // Scoped to the sensor, or another station's earlier stamp wins the bucket.
            $bucket->selectRaw('MIN(timestamp)')
                ->from('measurements')
                ->where('sensor_id', $this->selectedSensor?->id)
                ->groupByRaw('timestamp / ?', [$bucketSeconds]);
        });
    }

    /**
     * PostgreSQL only: `generate_series` lays out every slot and a left join
     * averages the readings onto it, so a missed slot is a row of nulls.
     * Buckets divide the epoch, not the local day, so DST never moves them.
     *
     * The blob is read by protocol key, not through ProtocolVersion::hydrate(),
     * so a protocol that renames a field has to change this query too. The
     * extremes fall back to the value where an entry has none (V1).
     *
     * @return SupportCollection<int, Bucket>
     */
    private function buckets(int $step, int $from, int $to): SupportCollection
    {
        $readings = $this->bucketed($step, $from, $to);

        foreach (['t' => 'temperature', 'h' => 'humidity', 'p' => 'pressure'] as $column => $field) {
            $readings
                ->selectRaw("AVG((data->>'{$field}')::int) AS {$column}_avg")
                ->selectRaw("MIN(COALESCE(data->>'{$field}_min', data->>'{$field}')::int) AS {$column}_min")
                ->selectRaw("MAX(COALESCE(data->>'{$field}_max', data->>'{$field}')::int) AS {$column}_max");
        }

        /** @var SupportCollection<int, Bucket> $buckets */
        $buckets = $this->slots($step, $from, $to)
            ->leftJoinSub($readings, 'reading', 'reading.bucket', '=', 'slot.bucket')
            ->addSelect(['t_avg', 'h_avg', 'p_avg', 't_min', 't_max', 'h_min', 'h_max', 'p_min', 'p_max'])
            ->get();

        return $buckets;
    }

    /**
     * Every slot of the window, holes included, to left-join averages onto.
     * Buckets divide the epoch, not the local day, so DST never moves them.
     */
    private function slots(int $step, int $from, int $to): QueryBuilder
    {
        return DB::query()
            ->fromRaw('generate_series(?::int, ?::int, ?::int) AS slot (bucket)', [
                intdiv($from, $step) * $step,
                intdiv($to, $step) * $step,
                $step,
            ])
            ->select('slot.bucket')
            ->orderBy('slot.bucket');
    }

    /**
     * The selected sensor's rows over whole edge buckets - so an edge
     * bucket's average does not depend on where the window opened - grouped
     * by bucket.
     *
     * @param  literal-string  $source
     */
    private function bucketed(int $step, int $from, int $to, string $source = 'measurements'): QueryBuilder
    {
        return DB::query()
            ->fromRaw($source)
            ->selectRaw('(timestamp / ?::int) * ?::int AS bucket', [$step, $step])
            ->where('sensor_id', $this->selectedSensor?->id)
            ->whereBetween('timestamp', [intdiv($from, $step) * $step, intdiv($to, $step) * $step + $step - 1])
            ->groupByRaw('1');
    }

    /**
     * The averages go back into a measurement in protocol units so reduction
     * and dew point run through the same code as a single reading. Pressure
     * extremes are reduced with the bucket's mean temperature; the sample
     * that read them kept none of its own.
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
     * Same slots as buckets(). Levels are in hundredths of a dB, so a
     * level's power is 10^(v / 1000) and back is 1000 log10. They average
     * as energy, weighted by the seconds each window heard: 50 and 60 dB
     * are 57.4 together, not 55, and the half minute after a boot does not
     * count as a whole window. LA10 and LA90 do not combine across windows,
     * so a bucket wider than one window carries their time-weighted mean in
     * dB - exact at ten minutes, an approximation above it.
     *
     * The bands come out of the jsonb array one row per entry and band
     * (`WITH ORDINALITY` keeps their order), are averaged per bucket and
     * band, and go back into one array per bucket in band order.
     *
     * @return SupportCollection<int, NoiseBucket>
     */
    private function noiseBuckets(int $step, int $from, int $to): SupportCollection
    {
        $levels = $this->bucketed($step, $from, $to)
            ->selectRaw($this->energyMean("data->'noise'->>'laeq'").' AS laeq')
            ->selectRaw($this->timeMean("data->'noise'->>'la10'").' AS la10')
            ->selectRaw($this->timeMean("data->'noise'->>'la90'").' AS la90')
            ->selectRaw("MAX((data->'noise'->>'lamax')::int) AS lamax")
            ->whereRaw("data->'noise' IS NOT NULL");

        $perBand = $this->bucketed($step, $from, $to, "measurements, jsonb_array_elements_text(measurements.data->'noise'->'bands') WITH ORDINALITY AS band (level, position)")
            ->selectRaw('position')
            ->selectRaw($this->energyMean('level').' AS level')
            ->groupByRaw('2');

        $bands = DB::query()
            ->fromSub($perBand, 'band')
            ->select('bucket')
            ->selectRaw('json_agg(level ORDER BY position) AS bands')
            ->groupBy('bucket');

        /** @var SupportCollection<int, NoiseBucket> $buckets */
        $buckets = $this->slots($step, $from, $to)
            ->leftJoinSub($levels, 'level', 'level.bucket', '=', 'slot.bucket')
            ->leftJoinSub($bands, 'band', 'band.bucket', '=', 'slot.bucket')
            ->addSelect(['laeq', 'la10', 'la90', 'lamax', 'bands'])
            ->get();

        return $buckets;
    }

    /**
     * Energy mean of a level in hundredths of a dB, weighted by the seconds
     * each window heard.
     *
     * @param  literal-string  $level
     * @return literal-string
     */
    private function energyMean(string $level): string
    {
        return '1000 * LOG(SUM('.self::NOISE_SECONDS.' * POWER(10, ('.$level.')::float8 / 1000)) / SUM('.self::NOISE_SECONDS.'))';
    }

    /**
     * Time-weighted mean of a level in dB, for the percentiles.
     *
     * @param  literal-string  $level
     * @return literal-string
     */
    private function timeMean(string $level): string
    {
        return 'SUM('.self::NOISE_SECONDS.' * ('.$level.')::float8) / SUM('.self::NOISE_SECONDS.')';
    }

    /**
     * Tenths of a dB: the band spread is tens of dB, and the payload is 26
     * numbers a slot.
     *
     * @param  NoiseBucket  $bucket
     * @return NoiseRow
     */
    private function plotNoise(object $bucket): array
    {
        $time = $this->wallClockMs($bucket->bucket);

        if ($bucket->laeq === null || $bucket->bands === null) {
            return [$time, $bucket->bucket, ...array_fill(0, 4 + NoiseWindow::BANDS_COUNT, null)];
        }

        $decibels = fn (float|int|string|null $value): ?float => $value === null ? null : round((float) $value / 100, 1);

        /** @var list<float|int|null> $bands */
        $bands = json_decode($bucket->bands, true, flags: JSON_THROW_ON_ERROR);

        return [
            $time,
            $bucket->bucket,
            $decibels($bucket->laeq),
            $decibels($bucket->la10),
            $decibels($bucket->la90),
            $decibels($bucket->lamax),
            ...array_map($decibels, $bands),
        ];
    }

    /** The same entry with another pressure, for reducing an extreme. */
    private function withPressure(MeasurementData $data, int $pressure): MeasurementData
    {
        return new MeasurementDataV1(
            temperature: $data->temperature,
            humidity: $data->humidity,
            pressure: $pressure,
        );
    }

    /** Two decimals: whole pascals, the sensor's resolution. Tenths drew the pressure line as a staircase. */
    private function seaLevelHpa(MeasurementData $data): float
    {
        return SeaLevelPressure::reduce($data, self::ALTITUDE_METRES)->hectopascals(2);
    }

    /** Null where there is none (see DewPoint::of); the chart draws a gap. */
    private function dewPointCelsius(MeasurementData $data): ?float
    {
        return DewPoint::of($data)?->celsius(2);
    }

    /**
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
     * Day min and max come off the entries' extremes, not their means: the
     * coldest sample sits below the coldest ten-minute mean.
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
            // Against one hour ago (six slots), or the oldest point if the day is shorter.
            'delta' => $now - (float) $day[max(0, count($day) - 7)],
            'dayMin' => min($lows),
            'dayMax' => max($highs),
        ];
    }
}

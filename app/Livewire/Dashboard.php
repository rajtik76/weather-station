<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Forecast;
use App\Models\Measurement;
use App\Models\Sensor;
use App\Models\StationEvent;
use App\Models\StationReport;
use App\Queries\ForecastAccuracy;
use App\Queries\MeasurementBuckets;
use App\ValueObject\ChartWindow;
use App\ValueObject\LocalTime;
use App\ValueObject\MeasurementDataV1;
use App\ValueObject\NoiseWindow;
use App\ValueObject\RainDetector;
use App\ValueObject\Readout;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
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
 * @property-read list<array{0: int, 1: int}> $rainSlots
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
 * @property-read LocalTime|null $lastMeasurement
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
 * @property-read array{at: string, ago: string, corrected: bool, horizons: list<ForecastHour>}|null $forecast
 * @property-read list<Score> $forecastAccuracy
 *
 * Chart rows are positional arrays to keep the JSON payload small. A bucket
 * row is `[wall-clock ms, t, h, p, dew point, epoch, tMin, tMax, hMin, hMax, pMin, pMax]`
 * with nulls for an empty slot; a reading row stops after the epoch.
 *
 * @phpstan-type ReadingRow array{0: int, 1: float, 2: float, 3: float, 4: ?float, 5: int}
 * @phpstan-type BucketRow array{0: int, 1: ?float, 2: ?float, 3: ?float, 4: ?float, 5: int, 6: ?float, 7: ?float, 8: ?float, 9: ?float, 10: ?float, 11: ?float}
 * @phpstan-type DayRow array{t: float, h: float, p: float, tMin: float, tMax: float, hMin: float, hMax: float, pMin: float, pMax: float, n: ?float}
 * A noise row is `[wall-clock ms, epoch, LAeq, LA10, LA90, LAmax, 26 bands]` in dB,
 * nulls for a slot without noise.
 * @phpstan-type NoiseRow list<int|float|null>
 * @phpstan-type ForecastHour array{hours: int, clock: string, t: float, tLow: float, tHigh: float, trend: string, rain: int, sky: array{icon: string, label: string, tone: string}}
 *
 * @phpstan-import-type Bucket from MeasurementBuckets
 * @phpstan-import-type NoiseBucket from MeasurementBuckets
 * @phpstan-import-type Horizon from Forecast
 * @phpstan-import-type Score from ForecastAccuracy
 */
#[Title('Station Log')]
class Dashboard extends Component
{
    private const float LATITUDE = 49.733242;

    private const float LONGITUDE = 13.399911;

    private const int LOCATION_RADIUS_METRES = 800;

    private const int SILENT_AFTER_SECONDS = 3 * ChartWindow::STEP_SECONDS;

    /** A forecast shows only while it starts this close to the newest reading. */
    private const int FORECAST_FRESH_SECONDS = 3 * ChartWindow::STEP_SECONDS;

    /** How far back the forecasts are scored against what came; the panel's label reads it. */
    public const int ACCURACY_DAYS = 7;

    /** Part of the accuracy's cache key: bump it when Score changes shape, so a deploy never reads the old one. */
    private const int ACCURACY_CACHE_SHAPE = 2;

    /** A forecast hour this close to the one before it shows no trend. */
    private const float FORECAST_STEADY_CELSIUS = 0.3;

    /** Navigator thinning: one reading per bucket once the record is large. */
    private const int OVERVIEW_BUCKET_SECONDS = 21600;

    private const int OVERVIEW_UNTHINNED_ROWS = 1500;

    private const int RECENT_TRANSMISSIONS = 3;

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

    /** Both ends are public, so `$wire.set()` reaches them without zoomTo(). */
    public function updatedFrom(): void
    {
        $this->normaliseWindow();
    }

    public function updatedTo(): void
    {
        $this->normaliseWindow();
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
        $buckets = $this->measurementBuckets()->readings($this->chartWindow());

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
        $hasNoise = $this->measurementsInWindow()
            ->whereRaw("data->'noise' IS NOT NULL")
            ->exists();

        if (! $hasNoise) {
            return [];
        }

        $buckets = $this->measurementBuckets()->noise($this->chartWindow());

        return array_values($buckets->map(fn (object $bucket): array => $this->plotNoise($bucket))->all());
    }

    /**
     * The noise slots the microphone heard rain in, as `[wall-clock ms,
     * epoch]` - the waterfall marks them. Each window is heard on its own
     * and a slot is rainy when any of its windows was: RainDetector is
     * calibrated on ten-minute windows, and on an averaged hour a shower
     * would fade into the dry windows beside it.
     *
     * @return list<array{0: int, 1: int}>
     */
    #[Computed]
    public function rainSlots(): array
    {
        if ($this->noise === []) {
            return [];
        }

        $rainy = [];

        foreach ($this->measurementBuckets()->spectra($this->chartWindow()) as $window) {
            /** @var list<int> $bands */
            $bands = json_decode($window->bands, true, flags: JSON_THROW_ON_ERROR);

            if (RainDetector::hears(array_map(fn (int $level): float => $level / 100, $bands))) {
                $rainy[(int) $window->bucket] = true;
            }
        }

        ksort($rainy);

        return array_map(
            fn (int $bucket): array => [LocalTime::of($bucket)->wallClockMs(), $bucket],
            array_keys($rainy),
        );
    }

    /**
     * The whole record for the navigator, thinned to one reading per six
     * hours once it is large enough to need it. The newest reading always
     * stays: the slider's axis ends on the last row, and without it the
     * right handle stops at the first reading of the newest bucket, up to
     * six hours short of now.
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
                    fn (Builder $query): Builder => $query->where(
                        fn (Builder $kept): Builder => $this->measurementBuckets()->firstPerBucket($kept, self::OVERVIEW_BUCKET_SECONDS)
                            ->orWhere('timestamp', $this->lastMeasurement?->timestamp)
                    )
                )
                ->orderBy('timestamp')
                ->get()
        );
    }

    /**
     * The window in the navigator's own axis units (see LocalTime::wallClockMs).
     *
     * @return array{from: int, to: int}
     */
    #[Computed]
    public function windowMs(): array
    {
        $window = $this->chartWindow();

        return [
            'from' => LocalTime::of($window->from)->wallClockMs(),
            'to' => LocalTime::of($window->to)->wallClockMs(),
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
        return $this->measurementsInWindow()->count();
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
                    'n' => $this->noiseAt($row[5]),
                ];
            }
        }

        return null;
    }

    /** LAeq of the noise bucket on the same slot, null when it heard nothing. */
    private function noiseAt(int $epoch): ?float
    {
        foreach ($this->noise as $row) {
            if ($row[1] === $epoch) {
                return $row[2] === null ? null : (float) $row[2];
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
        $window = $this->chartWindow();

        return [
            'from' => LocalTime::of($window->from)->stamp(),
            'to' => LocalTime::of($window->to)->stamp(),
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
                ->map(fn (Measurement $measurement): array => Readout::of($measurement->data)->toArray())
                ->all()
        );

        if ($day !== []) {
            return $day;
        }

        $newest = $this->newestPlottedReading();

        return $newest === null ? [] : [$newest];
    }

    /**
     * Noise (`n`) only when the day holds some: older rows and a dead
     * microphone carry none.
     *
     * @return array<string, array{now: float, delta: float, dayMin: float, dayMax: float}>
     */
    #[Computed]
    public function metrics(): array
    {
        if (! $this->hasReadings) {
            return [];
        }

        return array_filter([
            't' => $this->figures('t'),
            'h' => $this->figures('h'),
            'p' => $this->figures('p'),
            'n' => $this->noiseFigures(),
        ]);
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
                    $readout = Readout::of($measurement->data);

                    return [
                        'timestamp' => $measurement->timestamp,
                        'packet' => $measurement->data->jsonSerialize(),
                        // Rows written before the column existed.
                        ...LocalTime::of($measurement->created_at?->getTimestamp() ?? $measurement->timestamp)->forHumans(),
                        't' => $readout->temperature(),
                        'h' => $readout->humidity(),
                        'p' => $readout->pressure(),
                    ];
                })
                ->all()
        );
    }

    /**
     * The newest forecast, only while it starts from the station's current
     * record: a station that went quiet has nothing to forecast from, and an
     * old forecast would read as today's.
     *
     * @return array{at: string, ago: string, corrected: bool, horizons: list<ForecastHour>}|null
     */
    #[Computed]
    public function forecast(): ?array
    {
        if ($this->lastMeasurement === null) {
            return null;
        }

        $forecast = Forecast::query()
            ->where('sensor_id', $this->selectedSensor?->id)
            ->latest('issued_at')
            ->first();

        if ($forecast === null || $forecast->issued_at < $this->lastMeasurement->timestamp - self::FORECAST_FRESH_SECONDS) {
            return null;
        }

        // Each hour's trend against the one before it; the first against the newest reading.
        $newest = $this->measurements()->orderByDesc('timestamp')->first();
        $previous = $newest === null ? null : Readout::of($newest->data)->temperature();
        $horizons = [];

        foreach ($forecast->data as $horizon) {
            $horizons[] = $this->forecastHour($forecast->issued_at, $horizon, $previous);
            $previous = $horizon['temperature']['mid'];
        }

        return [
            // When it arrived, not the window it starts from: that is what the reader asks.
            ...LocalTime::of($forecast->created_at?->getTimestamp() ?? $forecast->issued_at)->forHumans(),
            'corrected' => $forecast->corrected,
            'horizons' => $horizons,
        ];
    }

    /**
     * The last week's forecasts scored against the readings that followed,
     * per horizon; empty until one has come true. Not the chart window: a
     * score over a zoomed hour would say nothing. Kept until the next
     * forecast: it comes with the upload that completes new scores. Fifteen
     * minutes at most, for when the service is down and none comes.
     *
     * One key per sensor, the forecast it was scored at inside the value: a
     * key per forecast is never read again once the next one comes, and the
     * database store only deletes an expired row it reads, so the table grew
     * by a row every ten minutes.
     *
     * @return list<Score>
     */
    #[Computed]
    public function forecastAccuracy(): array
    {
        $sensorId = $this->selectedSensor?->id;
        $newest = Forecast::query()->where('sensor_id', $sensorId)->max('issued_at');

        if ($newest === null) {
            return [];
        }

        $key = 'forecast-accuracy:v'.self::ACCURACY_CACHE_SHAPE.":{$sensorId}";
        $cached = Cache::get($key);

        if (is_array($cached) && ($cached['issuedAt'] ?? null) === $newest) {
            return $cached['scores'];
        }

        $scores = new ForecastAccuracy($sensorId)->since(now()->subDays(self::ACCURACY_DAYS)->getTimestamp());
        Cache::put($key, ['issuedAt' => $newest, 'scores' => $scores], now()->addMinutes(15));

        return $scores;
    }

    /**
     * Temperature and rain only: the service forecasts humidity and pressure
     * too, and they stay in the stored row, but nobody reads them ahead.
     *
     * @param  Horizon  $horizon
     * @param  float|null  $previous  the hour before's median, or the newest reading's temperature
     * @return ForecastHour
     */
    private function forecastHour(int $issuedAt, array $horizon, ?float $previous): array
    {
        $temperature = $horizon['temperature'];
        $change = $previous === null ? 0.0 : $temperature['mid'] - $previous;
        $at = $issuedAt + $horizon['hours'] * 3600;
        $rain = (int) round($horizon['rain_probability'] * 100);

        return [
            'hours' => $horizon['hours'],
            'clock' => LocalTime::of($at)->clock(),
            't' => round($temperature['mid'], 1),
            'tLow' => round($temperature['low'], 1),
            'tHigh' => round($temperature['high'], 1),
            // Under FORECAST_STEADY_CELSIUS either way reads as holding: the median wobbles by tenths.
            'trend' => match (true) {
                $change >= self::FORECAST_STEADY_CELSIUS => 'rising',
                $change <= -self::FORECAST_STEADY_CELSIUS => 'falling',
                default => 'steady',
            },
            'rain' => $rain,
            'sky' => $this->sky($at, $rain),
        ];
    }

    /**
     * The picture over a forecast hour. The models forecast rain, not cloud,
     * so it follows the rain chance alone; sun or moon by the real sunrise.
     *
     * @return array{icon: string, label: string, tone: string}
     */
    private function sky(int $timestamp, int $rain): array
    {
        $sun = date_sun_info($timestamp, self::LATITUDE, self::LONGITUDE);
        $isDay = $timestamp >= (int) $sun['sunrise'] && $timestamp < (int) $sun['sunset'];

        $tone = $isDay ? 'day' : 'night';

        return match (true) {
            $rain >= 60 => ['icon' => 'cloud-rain', 'label' => 'rain likely', 'tone' => 'rain'],
            $rain >= 30 => ['icon' => 'cloud-drizzle', 'label' => 'rain possible', 'tone' => 'rain'],
            $rain >= 10 => ['icon' => $isDay ? 'cloud-sun' : 'cloud-moon', 'label' => 'slight chance of rain', 'tone' => $tone],
            default => ['icon' => $isDay ? 'sun' : 'moon', 'label' => 'dry', 'tone' => $tone],
        };
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
            ...LocalTime::of($report->created_at?->getTimestamp() ?? 0)->forHumans(),
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
            'clockSynced' => LocalTime::of($syncedAt)->stamp(),
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
        return now(LocalTime::TIMEZONE)->year;
    }

    /**
     * Across the whole table, not the window. The station's own stamp, not
     * `created_at`: a buffered batch arrives late and says nothing about when
     * the sensor was last read.
     */
    #[Computed]
    public function lastMeasurement(): ?LocalTime
    {
        $timestamp = $this->measurements()->max('timestamp');

        return $timestamp === null ? null : LocalTime::of((int) $timestamp);
    }

    #[Computed]
    public function measuredAt(): ?string
    {
        return $this->lastMeasurement?->stamp();
    }

    #[Computed]
    public function measuredAgo(): ?string
    {
        return $this->lastMeasurement?->ago();
    }

    #[Computed]
    public function isSilent(): bool
    {
        return $this->lastMeasurement === null
            || $this->lastMeasurement->timestamp < now()->getTimestamp() - self::SILENT_AFTER_SECONDS;
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

    /**
     * @return Builder<Measurement>
     */
    private function measurementsInWindow(): Builder
    {
        $window = $this->chartWindow();

        return $this->measurements()->whereBetween('timestamp', [$window->from, $window->to]);
    }

    private function measurementBuckets(): MeasurementBuckets
    {
        return new MeasurementBuckets($this->selectedSensor?->id);
    }

    private function chartWindow(): ChartWindow
    {
        return ChartWindow::of($this->from, $this->to);
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
                    LocalTime::of($event->occurred_at)->wallClockMs(),
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

    private function normaliseWindow(): void
    {
        $window = ChartWindow::normalised($this->from, $this->to);

        $this->from = $window?->from;
        $this->to = $window?->to;
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
        $time = LocalTime::of($bucket->bucket)->wallClockMs();

        if ($bucket->t_avg === null || $bucket->h_avg === null || $bucket->p_avg === null) {
            return [$time, null, null, null, null, $bucket->bucket, null, null, null, null, null, null];
        }

        $mean = Readout::of(new MeasurementDataV1(
            temperature: (int) round((float) $bucket->t_avg),
            humidity: (int) round((float) $bucket->h_avg),
            pressure: (int) round((float) $bucket->p_avg),
        ));

        return [
            $time,
            Readout::hundredths($bucket->t_avg),
            Readout::hundredths($bucket->h_avg),
            $mean->pressure(),
            $mean->dewPoint(),
            $bucket->bucket,
            Readout::hundredths((float) $bucket->t_min),
            Readout::hundredths((float) $bucket->t_max),
            Readout::hundredths((float) $bucket->h_min),
            Readout::hundredths((float) $bucket->h_max),
            $mean->seaLevel((int) $bucket->p_min),
            $mean->seaLevel((int) $bucket->p_max),
        ];
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
        $time = LocalTime::of($bucket->bucket)->wallClockMs();

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

    /**
     * @param  Collection<int, Measurement>  $measurements
     * @return list<ReadingRow>
     */
    private function plot(Collection $measurements): array
    {
        return array_values(
            $measurements
                ->map(function (Measurement $measurement): array {
                    $readout = Readout::of($measurement->data);

                    return [
                        LocalTime::of($measurement->timestamp)->wallClockMs(),
                        $readout->temperature(),
                        $readout->humidity(),
                        $readout->pressure(),
                        $readout->dewPoint(),
                        $measurement->timestamp,
                    ];
                })
                ->all()
        );
    }

    /**
     * Day min and max come off the entries' extremes, not their means: the
     * coldest sample sits below the coldest ten-minute mean.
     *
     * @param  't'|'h'|'p'  $field
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

        return $this->summary($day, $lows, $highs);
    }

    /**
     * The day's LAeq, its extremes included: a window has no quietest
     * sample, and LAmax is a single door slam, not the loudest ten minutes.
     *
     * @return array{now: float, delta: float, dayMin: float, dayMax: float}|null
     */
    private function noiseFigures(): ?array
    {
        $levels = [];

        foreach ($this->lastDay as $entry) {
            if ($entry['n'] !== null) {
                $levels[] = $entry['n'];
            }
        }

        return $levels === [] ? null : $this->summary($levels, $levels, $levels);
    }

    /**
     * @param  non-empty-list<float>  $day
     * @param  non-empty-list<float>  $lows
     * @param  non-empty-list<float>  $highs
     * @return array{now: float, delta: float, dayMin: float, dayMax: float}
     */
    private function summary(array $day, array $lows, array $highs): array
    {
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

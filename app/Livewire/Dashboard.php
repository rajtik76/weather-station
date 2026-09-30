<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Forecast;
use App\Models\Measurement;
use App\Models\Sensor;
use App\Models\StationEvent;
use App\Models\StationReport;
use App\Queries\CachedForecastAccuracy;
use App\Queries\ForecastAccuracy;
use App\Queries\MeasurementBuckets;
use App\ValueObject\BoardReport;
use App\ValueObject\CarriesNoise;
use App\ValueObject\ChartRow;
use App\ValueObject\ChartWindow;
use App\ValueObject\DayFigures;
use App\ValueObject\ForecastHours;
use App\ValueObject\LocalTime;
use App\ValueObject\NoiseWindow;
use App\ValueObject\RainDetector;
use App\ValueObject\Readout;
use App\ValueObject\Sky;
use App\ValueObject\StationSite;
use App\ValueObject\Trace;
use App\ValueObject\Verdict;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * `#[Computed]` methods are declared as properties for Larastan.
 *
 * @property-read list<BucketRow> $readings
 * @property-read list<NoiseRow> $noise
 * @property-read list<LightRow> $light
 * @property-read list<array{0: int, 1: int}> $rainSlots
 * @property-read list<ReadingRow> $overview
 * @property-read array{from: int, to: int} $windowMs
 * @property-read bool $hasReadings
 * @property-read int $recordCount
 * @property-read list<DayRow> $lastDay
 * @property-read array<string, Figures> $metrics
 * @property-read bool $isNoiseCurrent
 * @property-read bool $isLightCurrent
 * @property-read list<array{timestamp: int, packet: array<string, int|array<string, int|list<int>>>, at: string, ago: string, t: float, h: float, p: float}> $recentTransmissions
 * @property-read array{lat: float, lng: float, radius: int} $approximateLocation
 * @property-read Report|null $stationReport
 * @property-read int $currentYear
 * @property-read Measurement|null $newestMeasurement
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
 * @property-read array{line: string, band: string, points: non-empty-list<array{x: float, y: float}>}|null $forecastCurve
 * @property-read bool|null $rainHeard
 * @property-read string|null $skyScene
 * @property-read list<Score> $forecastAccuracy
 * @property-read Score|null $accuracyScore
 * @property-read Answer|null $verdict
 *
 * @phpstan-import-type ReadingRow from ChartRow
 * @phpstan-import-type BucketRow from ChartRow
 * @phpstan-import-type NoiseRow from ChartRow
 * @phpstan-import-type LightRow from ChartRow
 * @phpstan-import-type DayRow from Readout
 * @phpstan-import-type Figures from DayFigures
 * @phpstan-import-type Hour from ForecastHours as ForecastHour
 * @phpstan-import-type Report from BoardReport
 * @phpstan-import-type Answer from Verdict
 * @phpstan-import-type Score from ForecastAccuracy
 */
#[Title('Station Log')]
class Dashboard extends Component
{
    private const int LOCATION_RADIUS_METRES = 800;

    private const int SILENT_AFTER_SECONDS = 3 * ChartWindow::STEP_SECONDS;

    /** A forecast shows only while it starts this close to the newest reading. */
    private const int FORECAST_FRESH_SECONDS = 3 * ChartWindow::STEP_SECONDS;

    /** How far back the forecasts are scored against what came; the panel's label reads it. */
    public const int ACCURACY_DAYS = 30;

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

    /**
     * The horizon the accuracy panel charts, in hours. Livewire state like the
     * channels, not #[Url], so a reload starts from three hours ahead.
     */
    public int $accuracyHorizon = 3;

    /** @var array<string, bool> */
    private const array DEFAULT_CHANNELS = ['t' => true, 'h' => true, 'd' => false];

    public function mount(): void
    {
        $this->normaliseWindow();
        $this->normaliseSensor();
    }

    /** Every render, a poll's included: new scores can take the chosen horizon's away. */
    public function render(): View
    {
        $this->normaliseAccuracyHorizon();

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

        return array_values($buckets->map(fn (object $bucket): array => ChartRow::bucket($bucket))->all());
    }

    /**
     * Protocol 3's noise over the same buckets as the readings. `[]` only
     * for a sensor that never sent any, and then the noise strips do not
     * render; a window before or between its noise keeps the strips, with
     * holes, so they do not vanish on a zoom.
     *
     * @return list<NoiseRow>
     */
    #[Computed]
    public function noise(): array
    {
        if (! $this->hasEverSent('noise')) {
            return [];
        }

        $buckets = $this->measurementBuckets()->noise($this->chartWindow());

        return array_values($buckets->map(fn (object $bucket): array => ChartRow::noise($bucket))->all());
    }

    /**
     * Whether the selected sensor has ever sent the blob key. A partial index
     * per key keeps this a lookup, not a scan of the sensor's history.
     *
     * @param  'noise'|'illuminance'  $key
     */
    private function hasEverSent(string $key): bool
    {
        return $this->measurements()->whereRaw("data->'{$key}' IS NOT NULL")->exists();
    }

    /**
     * Protocol 4's illuminance over the same buckets as the readings. `[]`
     * only for a sensor that never sent any (no VEML7700), and then the
     * light strip does not render; a window before or between its light
     * keeps the strip, with holes, so it does not vanish on a zoom.
     *
     * @return list<LightRow>
     */
    #[Computed]
    public function light(): array
    {
        if (! $this->hasEverSent('illuminance')) {
            return [];
        }

        $buckets = $this->measurementBuckets()->light($this->chartWindow());

        return array_values($buckets->map(fn (object $bucket): array => ChartRow::light($bucket))->all());
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
     * Whether the microphone hears rain in the newest window, by the rule the
     * waterfall marks with. Null when that window carries no spectrum: a dead
     * microphone or an older firmware must not read as a dry sky. Null while
     * the station is silent too: a shower it heard before going quiet would
     * otherwise read as rain now for the rest of the day.
     */
    #[Computed]
    public function rainHeard(): ?bool
    {
        if ($this->isSilent) {
            return null;
        }

        $data = $this->newestMeasurement?->data;

        if (! $data instanceof CarriesNoise || ! $data->noise instanceof NoiseWindow) {
            return null;
        }

        return RainDetector::hears(array_map(fn (int $level): float => $level / 100, $data->noise->bands));
    }

    /**
     * The photograph behind the sky, `{condition}-{day|night}` as named in
     * public/images/weather-backgrounds. Rain the microphone hears wins;
     * otherwise the next hour's rain chance on the thresholds the forecast
     * icons use, day or night by the real sunrise. The models forecast rain,
     * not cloud, so a clear picture only means a dry hour ahead and the
     * overcast pictures wait for a cloud reading. Null with neither: the
     * plain sky gradient stays rather than claim a weather it knows nothing
     * about, and so it does while the station is silent.
     */
    #[Computed]
    public function skyScene(): ?string
    {
        // A silent station says nothing about the sky now, and its last forecast even less.
        if ($this->isSilent) {
            return null;
        }

        return Sky::scene(now()->getTimestamp(), $this->rainHeard, $this->forecast['horizons'][0]['rain'] ?? null);
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

        return array_values(
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
                ->map(fn (Measurement $measurement): array => ChartRow::reading($measurement))
                ->all()
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
                    ...$this->lightAt($row[5]),
                ];
            }
        }

        return null;
    }

    /**
     * Lux of the light bucket on the same slot, nulls when it saw nothing.
     *
     * @return array{l: ?float, lMin: ?float, lMax: ?float}
     */
    private function lightAt(int $epoch): array
    {
        foreach ($this->light as $row) {
            if ($row[1] === $epoch) {
                return ['l' => $row[2], 'lMin' => $row[3], 'lMax' => $row[4]];
            }
        }

        return ['l' => null, 'lMin' => null, 'lMax' => null];
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
     * Whether the newest reading carries noise. The noise readout summarises
     * the day, so after the microphone drops out its "now" is the last level
     * heard, hours old; the sky shows only what is current.
     */
    #[Computed]
    public function isNoiseCurrent(): bool
    {
        $newest = $this->lastDay === [] ? null : $this->lastDay[array_key_last($this->lastDay)];

        return $newest !== null && $newest['n'] !== null;
    }

    /** Whether the newest reading carries light; same reason as isNoiseCurrent(). */
    #[Computed]
    public function isLightCurrent(): bool
    {
        $newest = $this->lastDay === [] ? null : $this->lastDay[array_key_last($this->lastDay)];

        return $newest !== null && $newest['l'] !== null;
    }

    /**
     * @return array<string, Figures>
     */
    #[Computed]
    public function metrics(): array
    {
        // The readouts follow the window's; an empty day has nothing to summarise either.
        if (! $this->hasReadings || $this->lastDay === []) {
            return [];
        }

        return DayFigures::of($this->lastDay)->metrics();
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

        // A row without hours has nothing to show, and the sky reads its first hour.
        if ($forecast === null || $forecast->data === [] || $forecast->issued_at < $this->lastMeasurement->timestamp - self::FORECAST_FRESH_SECONDS) {
            return null;
        }

        $newest = $this->newestMeasurement;
        $horizons = ForecastHours::of($forecast, $newest === null ? null : Readout::of($newest->data)->temperature());

        return [
            // When it arrived, not the window it starts from: that is what the reader asks.
            ...LocalTime::of($forecast->created_at?->getTimestamp() ?? $forecast->issued_at)->forHumans(),
            'corrected' => $forecast->corrected,
            'horizons' => $horizons,
        ];
    }

    /**
     * The sky's curve: the reading now, then each forecast hour's median over
     * its range, one column each so the hour labels line up under the points.
     * The ticks label the y-axis on the same scale: the band's bottom, middle and top.
     *
     * @return array{line: string, band: string, points: non-empty-list<array{x: float, y: float}>, ticks: non-empty-list<array{value: float, y: float}>}|null
     */
    #[Computed]
    public function forecastCurve(): ?array
    {
        $now = $this->metrics['t']['now'] ?? null;

        if ($this->forecast === null || $now === null) {
            return null;
        }

        $horizons = $this->forecast['horizons'];
        $middles = [$now, ...array_column($horizons, 't')];
        $lows = [$now, ...array_column($horizons, 'tLow')];
        $highs = [$now, ...array_column($horizons, 'tHigh')];
        ['low' => $low, 'high' => $high, 'ticks' => $ticks] = Trace::axis(min($lows), max($highs));
        $line = Trace::centred($middles, $low, $high);

        return [
            'line' => $line->line(),
            'band' => Trace::centred($highs, $low, $high)->band(Trace::centred($lows, $low, $high)),
            'points' => $line->points,
            'ticks' => $ticks,
        ];
    }

    /**
     * The last month's forecasts scored against the readings that followed,
     * per horizon. Not the chart window: a score over a zoomed hour would
     * say nothing.
     *
     * @return list<Score>
     */
    #[Computed]
    public function forecastAccuracy(): array
    {
        return new CachedForecastAccuracy($this->selectedSensor?->id)->lastDays(self::ACCURACY_DAYS);
    }

    /**
     * The chosen horizon's score, or the first one scored while the chosen
     * one has not come true yet - three hours ahead needs three hours.
     * render() then moves the choice onto it.
     *
     * @return Score|null
     */
    #[Computed]
    public function accuracyScore(): ?array
    {
        $scores = $this->forecastAccuracy;

        foreach ($scores as $score) {
            if ($score['hours'] === $this->accuracyHorizon) {
                return $score;
            }
        }

        return $scores[0] ?? null;
    }

    /**
     * @return Answer|null
     */
    #[Computed]
    public function verdict(): ?array
    {
        return Verdict::of($this->forecastAccuracy);
    }

    /**
     * The newest `station` object of the selected sensor.
     *
     * @return Report|null
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

        return BoardReport::of($report)->toArray();
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

    #[Computed]
    public function currentYear(): int
    {
        return now(LocalTime::TIMEZONE)->year;
    }

    /**
     * Across the whole table, not the window. By the station's own stamp, not
     * `created_at`: a buffered batch arrives late and says nothing about when
     * the sensor was last read. One query for the status line, the rain and
     * the forecast's first trend.
     */
    #[Computed]
    public function newestMeasurement(): ?Measurement
    {
        return $this->measurements()->orderByDesc('timestamp')->first();
    }

    #[Computed]
    public function lastMeasurement(): ?LocalTime
    {
        return $this->newestMeasurement === null ? null : LocalTime::of($this->newestMeasurement->timestamp);
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

    /**
     * Pins the choice to the horizon the panel shows, so the segmented control
     * never marks one while the chart draws another.
     */
    private function normaliseAccuracyHorizon(): void
    {
        // Computed before a sensor switch, they would score the old one.
        unset($this->forecastAccuracy, $this->accuracyScore, $this->verdict);

        $shown = $this->accuracyScore['hours'] ?? null;

        if ($shown !== null && $shown !== $this->accuracyHorizon) {
            $this->accuracyHorizon = $shown;
        }
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
}

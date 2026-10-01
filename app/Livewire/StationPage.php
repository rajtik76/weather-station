<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Forecast;
use App\Models\Measurement;
use App\Models\Sensor;
use App\Models\StationReport;
use App\Queries\CachedForecastAccuracy;
use App\Queries\ForecastAccuracy;
use App\Queries\LatestForecast;
use App\Queries\LatestStationReport;
use App\Queries\StationRecord;
use App\Queries\Stations;
use App\ValueObject\BoardReport;
use App\ValueObject\ChartWindow;
use App\ValueObject\ForecastChart;
use App\ValueObject\ForecastHours;
use App\ValueObject\LocalTime;
use App\ValueObject\Verdict;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * What every page of the redesign reads about the selected station: its
 * newest reading for the header, the newest forecast with the chart around
 * it, and how the last month of forecasts scored.
 *
 * `#[Computed]` methods are declared as properties for Larastan.
 *
 * @phpstan-import-type Hour from ForecastHours as ForecastHour
 * @phpstan-import-type Answer from Verdict
 * @phpstan-import-type Score from ForecastAccuracy
 * @phpstan-import-type Report from BoardReport
 *
 * @property-read Collection<int, Sensor> $sensors
 * @property-read ?Sensor $selectedSensor
 * @property-read bool $hasSensorChoice
 * @property-read ?Measurement $newestMeasurement
 * @property-read bool $isSilent
 * @property-read ?string $measuredAt
 * @property-read array{at: string, ago: string, corrected: bool, horizons: list<ForecastHour>}|null $forecast
 * @property-read ?ForecastChart $forecastChart
 * @property-read list<Score> $forecastAccuracy
 * @property-read Answer|null $verdict
 * @property-read Report|null $stationReport
 * @property-read int $currentYear
 */
abstract class StationPage extends Component
{
    private const int SILENT_AFTER_SECONDS = 3 * ChartWindow::STEP_SECONDS;

    /** How far back the forecasts are scored against what came. */
    public const int ACCURACY_DAYS = 30;

    /** Sensor slug, null for the first registered. Slug rather than id so the link survives a reseed. */
    #[Url]
    public ?string $sensor = null;

    public function mount(): void
    {
        $this->normaliseSensor();
    }

    public function updatedSensor(): void
    {
        $this->normaliseSensor();
    }

    /**
     * The stations the picker offers (Stations).
     *
     * @return Collection<int, Sensor>
     */
    #[Computed]
    public function sensors(): Collection
    {
        return new Stations()->all();
    }

    #[Computed]
    public function selectedSensor(): ?Sensor
    {
        return $this->sensors->firstWhere('slug', $this->sensor) ?? $this->sensors->first();
    }

    /** The header's picker shows only once there is a choice. */
    #[Computed]
    public function hasSensorChoice(): bool
    {
        return $this->sensors->count() >= 2;
    }

    /**
     * The selected sensor carried into a link to another page, nothing while
     * there is only one, so the default keeps a clean URL.
     *
     * @return array{sensor?: string}
     */
    public function sensorQuery(): array
    {
        return $this->hasSensorChoice && $this->sensor !== null ? ['sensor' => $this->sensor] : [];
    }

    /** By the station's own stamp: a buffered batch arrives late and says nothing about when the sensor was read. */
    #[Computed]
    public function newestMeasurement(): ?Measurement
    {
        return $this->record()->newest();
    }

    #[Computed]
    public function isSilent(): bool
    {
        return $this->newestMeasurement === null
            || $this->newestMeasurement->timestamp < now()->getTimestamp() - self::SILENT_AFTER_SECONDS;
    }

    #[Computed]
    public function measuredAt(): ?string
    {
        return $this->newestMeasurement === null ? null : LocalTime::of($this->newestMeasurement->timestamp)->stamp();
    }

    /**
     * The newest forecast, only while it starts from the station's current record.
     *
     * @return array{at: string, ago: string, corrected: bool, horizons: list<ForecastHour>}|null
     */
    #[Computed]
    public function forecast(): ?array
    {
        $newest = $this->newestMeasurement;

        if ($newest === null) {
            return null;
        }

        $forecast = new LatestForecast($this->selectedSensor?->id)->startingFrom($newest);

        if (! $forecast instanceof Forecast) {
            return null;
        }

        return [
            ...LocalTime::of($forecast->created_at?->getTimestamp() ?? $forecast->issued_at)->forHumans(),
            'corrected' => $forecast->corrected,
            'horizons' => ForecastHours::of($forecast),
        ];
    }

    /** The last six hours as measured, then the forecast from the newest reading on. */
    #[Computed]
    public function forecastChart(): ?ForecastChart
    {
        $newest = $this->newestMeasurement;
        $horizons = $this->forecast['horizons'] ?? [];

        if ($newest === null || $horizons === []) {
            return null;
        }

        $readings = $this->record()->temperaturesSince($newest->timestamp - ForecastChart::HISTORY_SECONDS);

        return $readings === [] ? null : ForecastChart::of($readings, $horizons);
    }

    /**
     * The last month's forecasts scored against the readings that followed, per horizon.
     *
     * @return list<Score>
     */
    #[Computed]
    public function forecastAccuracy(): array
    {
        return new CachedForecastAccuracy($this->selectedSensor?->id)->lastDays(self::ACCURACY_DAYS);
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
     * The newest `station` object of the selected sensor, as the firmware sent it.
     *
     * @return Report|null
     */
    #[Computed]
    public function stationReport(): ?array
    {
        $report = new LatestStationReport($this->selectedSensor?->id)->find();

        return $report instanceof StationReport ? BoardReport::of($report)->toArray() : null;
    }

    /** The footer's copyright year, by the station's clock: on New Year's Eve UTC is an hour behind. */
    #[Computed]
    public function currentYear(): int
    {
        return now(LocalTime::TIMEZONE)->year;
    }

    /** The selected sensor's readings; with no sensor the id is null and nothing matches, which is the empty page. */
    protected function record(): StationRecord
    {
        return new StationRecord($this->selectedSensor?->id);
    }

    /** The picker is bound to the property, so it must hold a real slug or the select shows blank. */
    private function normaliseSensor(): void
    {
        unset($this->selectedSensor);

        $this->sensor = $this->selectedSensor?->slug;
    }
}

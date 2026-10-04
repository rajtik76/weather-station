<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Forecast;
use App\Models\Measurement;
use App\Models\Sensor;
use App\Models\StationReport;
use App\Queries\CachedForecastAccuracy;
use App\Queries\ForecastAccuracy;
use App\Queries\HourForecasts;
use App\Queries\LatestForecast;
use App\Queries\LatestStationReport;
use App\Queries\StationRecord;
use App\Queries\Stations;
use App\ValueObject\BoardReport;
use App\ValueObject\ChartWindow;
use App\ValueObject\ForecastChart;
use App\ValueObject\ForecastHour;
use App\ValueObject\ForecastHours;
use App\ValueObject\LocalTime;
use App\ValueObject\Verdict;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * What every page reads about the selected station.
 *
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
 * @property-read ?string $measuredAgo
 * @property-read array{at: string, ago: string, corrected: bool, horizons: list<ForecastHour>}|null $forecast
 * @property-read list<array{at: int, t: float}> $recentTemperatures
 * @property-read ?ForecastChart $forecastChart
 * @property-read list<Score> $forecastAccuracy
 * @property-read Answer|null $verdict
 * @property-read Report|null $stationReport
 * @property-read int $currentYear
 */
abstract class StationPage extends Component
{
    private const int SILENT_AFTER_SECONDS = 3 * ChartWindow::STEP_SECONDS;

    /** Days of forecasts scored. */
    public const int ACCURACY_DAYS = 30;

    /** Slug rather than id so links survive a reseed; null picks the first sensor. */
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

    #[Computed]
    public function hasSensorChoice(): bool
    {
        return $this->sensors->count() >= 2;
    }

    /**
     * Empty with a single sensor to keep URLs clean.
     *
     * @return array{sensor?: string}
     */
    public function sensorQuery(): array
    {
        return $this->hasSensorChoice && $this->sensor !== null ? ['sensor' => $this->sensor] : [];
    }

    /** By the station's own stamp: a buffered batch arrives late. */
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

    #[Computed]
    public function measuredAgo(): ?string
    {
        return $this->newestMeasurement === null ? null : LocalTime::of($this->newestMeasurement->timestamp)->ago();
    }

    /**
     * Only while the forecast starts from the station's newest reading.
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
            'horizons' => ForecastHours::of(new HourForecasts($this->selectedSensor?->id)->upTo($forecast), $this->recentTemperatures),
        ];
    }

    /**
     * The chart's history, which also holds the readings the hour's forecasts were issued from.
     *
     * @return list<array{at: int, t: float}>
     */
    #[Computed]
    public function recentTemperatures(): array
    {
        $newest = $this->newestMeasurement;

        return $newest === null ? [] : $this->record()->temperaturesSince($newest->timestamp - ForecastChart::HISTORY_SECONDS);
    }

    #[Computed]
    public function forecastChart(): ?ForecastChart
    {
        $newest = $this->newestMeasurement;
        $horizons = $this->forecast['horizons'] ?? [];

        if ($newest === null || $horizons === []) {
            return null;
        }

        $readings = $this->recentTemperatures;

        return $readings === [] ? null : ForecastChart::of($readings, $horizons);
    }

    /**
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
     * @return Report|null
     */
    #[Computed]
    public function stationReport(): ?array
    {
        $report = new LatestStationReport($this->selectedSensor?->id)->find();

        return $report instanceof StationReport ? BoardReport::of($report)->toArray() : null;
    }

    /** Local year: UTC lags an hour on New Year's Eve. */
    #[Computed]
    public function currentYear(): int
    {
        return now(LocalTime::TIMEZONE)->year;
    }

    /** Null id matches nothing. */
    protected function record(): StationRecord
    {
        return new StationRecord($this->selectedSensor?->id);
    }

    /** The picker is bound to the property: it must hold a real slug or the select shows blank. */
    private function normaliseSensor(): void
    {
        unset($this->selectedSensor);

        $this->sensor = $this->selectedSensor?->slug;
    }
}

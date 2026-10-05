<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Queries\ChartSeries;
use App\Queries\RecentTransmissions;
use App\Queries\RecordOverview;
use App\Queries\StationEventMarks;
use App\ValueObject\ChannelSelection;
use App\ValueObject\ChartRow;
use App\ValueObject\ChartWindow;
use App\ValueObject\LightScale;
use App\ValueObject\LocalTime;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/**
 * The charts page; does not poll, so a zoomed reader's strips hold still.
 *
 * @phpstan-import-type BucketRow from ChartRow
 * @phpstan-import-type NoiseRow from ChartRow
 * @phpstan-import-type LightRow from ChartRow
 * @phpstan-import-type ReadingRow from ChartRow
 * @phpstan-import-type Transmission from RecentTransmissions
 * @phpstan-import-type Mark from StationEventMarks
 *
 * @property-read list<BucketRow> $readings
 * @property-read list<NoiseRow> $noise
 * @property-read list<LightRow> $light
 * @property-read list<array{0: int, 1: int}> $rainSlots
 * @property-read list<ReadingRow> $overview
 * @property-read array{from: int, to: int} $windowMs
 * @property-read array{from: string, to: string} $window
 * @property-read bool $hasReadings
 * @property-read int $recordCount
 * @property-read bool $isZoomed
 * @property-read list<string> $hiddenChannels
 * @property-read LightScale $lightAxis
 * @property-read list<Transmission> $recentTransmissions
 * @property-read list<Mark> $stationEvents
 */
#[Title('Balcony Station Charts')]
class Charts extends StationPage
{
    private const int RECENT_TRANSMISSIONS = 3;

    /** Zoomed window as UTC epochs, null for the default; instants so a drag can land anywhere. */
    #[Url]
    public ?int $from = null;

    #[Url]
    public ?int $to = null;

    /**
     * Locked so `$wire.set()` cannot bypass the last-channel guard; not #[Url].
     *
     * @var array<string, bool>
     */
    #[Locked]
    public array $channels = ChannelSelection::DEFAULTS;

    #[Locked]
    public string $lightScale = LightScale::DEFAULT;

    public function mount(): void
    {
        parent::mount();
        $this->normaliseWindow();
    }

    public function render(): View
    {
        return view('livewire.charts');
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

    public function toggleChannel(string $channel): void
    {
        $this->channels = ChannelSelection::of($this->channels)->toggle($channel)->toArray();
    }

    public function useLightScale(string $scale): void
    {
        $this->lightScale = LightScale::of($scale)->name;
    }

    #[Computed]
    public function lightAxis(): LightScale
    {
        return LightScale::of($this->lightScale);
    }

    public function isLastChannel(string $channel): bool
    {
        return ChannelSelection::of($this->channels)->isLast($channel);
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function hiddenChannels(): array
    {
        return ChannelSelection::of($this->channels)->hidden();
    }

    #[Computed]
    public function isZoomed(): bool
    {
        return $this->from !== null && $this->to !== null;
    }

    /**
     * @return list<BucketRow>
     */
    #[Computed]
    public function readings(): array
    {
        return $this->series()->readings($this->chartWindow());
    }

    /**
     * @return list<NoiseRow>
     */
    #[Computed]
    public function noise(): array
    {
        return $this->series()->noise($this->chartWindow());
    }

    /**
     * @return list<LightRow>
     */
    #[Computed]
    public function light(): array
    {
        return $this->series()->light($this->chartWindow());
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    #[Computed]
    public function rainSlots(): array
    {
        return $this->noise === [] ? [] : $this->series()->rainSlots($this->chartWindow());
    }

    #[Computed]
    public function hasReadings(): bool
    {
        return $this->readings !== [];
    }

    #[Computed]
    public function recordCount(): int
    {
        return $this->series()->count($this->chartWindow());
    }

    /**
     * @return list<ReadingRow>
     */
    #[Computed]
    public function overview(): array
    {
        return new RecordOverview($this->selectedSensor?->id)->rows($this->newestMeasurement?->timestamp);
    }

    /**
     * In the navigator's axis units (LocalTime::wallClockMs).
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
     * @return list<Transmission>
     */
    #[Computed]
    public function recentTransmissions(): array
    {
        return new RecentTransmissions($this->selectedSensor?->id)->latest(self::RECENT_TRANSMISSIONS);
    }

    /**
     * @return list<Mark>
     */
    #[Computed]
    public function stationEvents(): array
    {
        return new StationEventMarks($this->selectedSensor?->id)->all();
    }

    private function series(): ChartSeries
    {
        return new ChartSeries($this->selectedSensor?->id);
    }

    private function chartWindow(): ChartWindow
    {
        return ChartWindow::of($this->from, $this->to);
    }

    /** Sorts, clamps to the present and bounds the span; the ends come from the query string. */
    private function normaliseWindow(): void
    {
        $window = ChartWindow::normalised($this->from, $this->to);

        $this->from = $window?->from;
        $this->to = $window?->to;
    }
}

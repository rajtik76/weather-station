<?php

declare(strict_types=1);

namespace App\ValueObject;

/**
 * The overview's forecast chart: the last six hours measured on the left, the forecast median and the hour's range on the right. Coordinates are percentages of a 100 × 100 box, y growing down.
 *
 * @phpstan-type Point array{x: float, y: float}
 * @phpstan-type Tick array{value: float, y: float}
 */
final readonly class ForecastChart
{
    /** Seconds of history on the left half. */
    public const int HISTORY_SECONDS = 6 * 3600;

    private const int HORIZON_HOURS = 6;

    private const float PADDING = 0.12;

    /**
     * @param  non-empty-list<Point>  $measured
     * @param  non-empty-list<Point>  $median
     * @param  non-empty-list<Point>  $high
     * @param  non-empty-list<Point>  $low
     * @param  list<Tick>  $ticks
     * @param  list<array{x: float, y: float, clock: string, t: float, rain: int}>  $hours
     */
    private function __construct(
        public array $measured,
        public array $median,
        public array $high,
        public array $low,
        public array $ticks,
        public array $hours,
    ) {}

    /**
     * @param  non-empty-list<array{at: int, t: float}>  $readings  oldest first, the newest is "now"
     * @param  non-empty-list<ForecastHour>  $horizons
     */
    public static function of(array $readings, array $horizons): self
    {
        $now = $readings[count($readings) - 1];
        $values = [...array_column($readings, 't'), ...array_column($horizons, 'tLow'), ...array_column($horizons, 'tHigh')];
        $span = max(max($values) - min($values), 1.0);
        $low = min($values) - $span * self::PADDING;
        $high = max($values) + $span * self::PADDING;
        $y = fn (float $value): float => round(($high - $value) / ($high - $low) * 100, 2);
        // By the hour's epoch, not its number: a forecast issued a window early lands left.
        $hourX = fn (ForecastHour $hour): float => round(50 + ($hour->at - $now['at']) / (self::HORIZON_HOURS * 3600) * 50, 2);
        $start = ['x' => 50.0, 'y' => $y($now['t'])];

        return new self(
            measured: array_map(fn (array $reading): array => [
                'x' => round(max(0.0, 50 - ($now['at'] - $reading['at']) / self::HISTORY_SECONDS * 50), 2),
                'y' => $y($reading['t']),
            ], $readings),
            median: [$start, ...array_map(fn (ForecastHour $hour): array => ['x' => $hourX($hour), 'y' => $y($hour->t)], $horizons)],
            high: [$start, ...array_map(fn (ForecastHour $hour): array => ['x' => $hourX($hour), 'y' => $y($hour->tHigh)], $horizons)],
            low: [$start, ...array_map(fn (ForecastHour $hour): array => ['x' => $hourX($hour), 'y' => $y($hour->tLow)], $horizons)],
            ticks: Trace::ticks($low, $high, $y),
            hours: array_map(fn (ForecastHour $hour): array => [
                'x' => $hourX($hour),
                'y' => $y($hour->t),
                'clock' => $hour->clock,
                't' => $hour->t,
                'rain' => $hour->rain,
            ], $horizons),
        );
    }

    public function measuredLine(): string
    {
        return Trace::through($this->measured)->line();
    }

    public function medianLine(): string
    {
        return Trace::through($this->median)->line();
    }

    public function band(): string
    {
        return Trace::through($this->high)->band(Trace::through($this->low));
    }

    /**
     * The newest reading, at the middle of the box.
     *
     * @return Point
     */
    public function now(): array
    {
        return $this->median[0];
    }
}

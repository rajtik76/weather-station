<?php

declare(strict_types=1);

namespace App\ValueObject;

/**
 * A series laid out as SVG path data in a 100 × 100 box, y growing down: the
 * traces the server draws itself: the readout sparklines and the forecast
 * chart's lines and range. The SVG stretches the box with
 * `preserveAspectRatio="none"`, so the coordinates double as percentages for
 * anything positioned over it.
 */
final readonly class Trace
{
    /** Room above and below the data, in box units, so a line never runs along an edge. */
    private const float MARGIN = 8.0;

    /**
     * @param  non-empty-list<array{x: float, y: float}>  $points
     */
    private function __construct(public array $points) {}

    /**
     * Evenly from edge to edge, scaled to the values' own extremes unless
     * `$low`/`$high` are given. A single value draws as a flat line.
     *
     * @param  non-empty-list<float>  $values
     */
    public static function spanning(array $values, ?float $low = null, ?float $high = null): self
    {
        if (count($values) === 1) {
            $values = [$values[0], $values[0]];
        }

        $last = count($values) - 1;
        $xs = array_map(fn (int $index): float => $index / $last * 100, array_keys($values));

        return self::lay($values, $xs, $low ?? min($values), $high ?? max($values));
    }

    /**
     * Points already laid out in the box, for a chart that places them itself.
     *
     * @param  non-empty-list<array{x: float, y: float}>  $points
     */
    public static function through(array $points): self
    {
        return new self($points);
    }

    public function line(): string
    {
        return implode('', array_map(
            fn (array $point, int $index): string => ($index === 0 ? 'M' : 'L').$point['x'].','.$point['y'],
            $this->points,
            array_keys($this->points),
        ));
    }

    /** The region between this line and a lower one over the same x positions. */
    public function band(self $lower): string
    {
        $back = array_map(
            fn (array $point): string => 'L'.$point['x'].','.$point['y'],
            array_reverse($lower->points),
        );

        return $this->line().implode('', $back).'Z';
    }

    /**
     * @param  non-empty-list<float>  $values
     * @param  non-empty-list<float>  $xs
     */
    private static function lay(array $values, array $xs, float $low, float $high): self
    {
        $points = [];

        foreach ($values as $index => $value) {
            $points[] = [
                'x' => round($xs[$index], 2),
                'y' => self::level($value, $low, $high),
            ];
        }

        return new self($points);
    }

    /** Where a value sits in the box, y growing down. A flat series sits in the middle rather than dividing by zero. */
    private static function level(float $value, float $low, float $high): float
    {
        $share = $high > $low ? ($value - $low) / ($high - $low) : 0.5;

        return round(self::MARGIN + (1 - $share) * (100 - 2 * self::MARGIN), 2);
    }
}

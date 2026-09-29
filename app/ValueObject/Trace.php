<?php

declare(strict_types=1);

namespace App\ValueObject;

/**
 * A series laid out as SVG path data in a 100 × 100 box, y growing down: the
 * traces the server draws itself, the readout sparklines and the forecast
 * curve over the sky. The SVG stretches the box with
 * `preserveAspectRatio="none"`, so the coordinates double as percentages for
 * anything positioned over it.
 */
final readonly class Trace
{
    /** Room above and below the data, in box units, so a line never runs along an edge. */
    private const float MARGIN = 8.0;

    /** How far inside the data's extremes the outer axis labels sit, as a share of its range. */
    private const float AXIS_INSET = 0.2;

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
     * One point in the middle of each of `count($values)` equal columns, so
     * labels in a grid of the same columns sit under their points.
     *
     * @param  non-empty-list<float>  $values
     */
    public static function centred(array $values, float $low, float $high): self
    {
        $count = count($values);
        $xs = array_map(fn (int $index): float => ($index + 0.5) / $count * 100, array_keys($values));

        return self::lay($values, $xs, $low, $high);
    }

    public function line(): string
    {
        return implode('', array_map(
            fn (array $point, int $index): string => ($index === 0 ? 'M' : 'L').$point['x'].','.$point['y'],
            $this->points,
            array_keys($this->points),
        ));
    }

    /** The line closed along the bottom of the box, for a fill under it. */
    public function area(): string
    {
        $first = $this->points[0]['x'];
        $last = $this->points[count($this->points) - 1]['x'];

        return $this->line()."L{$last},100L{$first},100Z";
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
     * A three-level y-axis for a trace laid against `$low`-`$high`: top,
     * middle and bottom on tenths, the outer two AXIS_INSET of the range in
     * from the extremes so the data reaches past them, and narrowed by a
     * tenth when the middle would fall between two. A range too narrow for
     * that, a flat one included, gets its middle alone.
     *
     * @return array{low: float, high: float, ticks: non-empty-list<array{value: float, y: float}>}
     */
    public static function axis(float $low, float $high): array
    {
        $inset = ($high - $low) * self::AXIS_INSET;
        $bottom = (int) ceil(round(($low + $inset) * 10, 6));
        $top = (int) floor(round(($high - $inset) * 10, 6));

        if (($top - $bottom) % 2 !== 0) {
            $top--;
        }

        $tenths = $top - $bottom >= 2
            ? [$top, intdiv($bottom + $top, 2), $bottom]
            : [(int) round(($low + $high) * 5)];

        return [
            'low' => $low,
            'high' => $high,
            'ticks' => array_map(fn (int $tenth): array => [
                'value' => $tenth / 10.0,
                'y' => self::level($tenth / 10.0, $low, $high),
            ], $tenths),
        ];
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

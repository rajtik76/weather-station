<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Enums\ChartRange;

/**
 * The span the strips show, as UTC epochs. Both ends of a zoom arrive from
 * the query string and from drags that may have been stray clicks, so they
 * go through normalised() before they are kept.
 */
final readonly class ChartWindow
{
    /** What the page opens on while nothing is zoomed. */
    public const ChartRange DEFAULT_RANGE = ChartRange::Week;

    /** Reporting interval of the station. */
    public const int STEP_SECONDS = 600;

    /** Four readings; fewer would not make a line. */
    private const int MIN_SPAN_SECONDS = 4 * self::STEP_SECONDS;

    /**
     * A month. A strip is ~1000 px wide; hourly means over a month still
     * show a day's swing, anything wider averages it away. The navigator
     * spans the whole record regardless.
     */
    private const int MAX_SPAN_SECONDS = 2592000;

    private function __construct(public int $from, public int $to) {}

    /** The zoom where it is set, the default range up to now where it is not. */
    public static function of(?int $from, ?int $to): self
    {
        $now = now()->getTimestamp();

        return new self($from ?? $now - self::DEFAULT_RANGE->durationSeconds(), $to ?? $now);
    }

    /**
     * Ordered, at least MIN_SPAN, at most MAX_SPAN, not in the future. Too
     * wide is clipped from the front: the newer end is the one chosen. Null
     * unless both ends are given, so the default keeps following now.
     */
    public static function normalised(?int $from, ?int $to): ?self
    {
        if ($from === null || $to === null) {
            return null;
        }

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $to = min($to, now()->getTimestamp());

        return new self(max(0, $to - self::MAX_SPAN_SECONDS, min($from, $to - self::MIN_SPAN_SECONDS)), $to);
    }

    public function span(): int
    {
        return $this->to - $this->from;
    }

    /** The preset the span falls to; bucket width and label precision key off it. */
    public function range(): ChartRange
    {
        return ChartRange::forSpan($this->span());
    }
}

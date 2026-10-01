<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Enums\ChartRange;

/**
 * The span the strips show, as UTC epochs. Ends arrive from the query string, so they go through normalised().
 */
final readonly class ChartWindow
{
    public const ChartRange DEFAULT_RANGE = ChartRange::Week;

    /** Reporting interval, seconds. */
    public const int STEP_SECONDS = 600;

    /** Four readings: fewer make no line. */
    private const int MIN_SPAN_SECONDS = 4 * self::STEP_SECONDS;

    /** A month: hourly means over it still show a day's swing, wider averages it away. */
    private const int MAX_SPAN_SECONDS = 2592000;

    private function __construct(public int $from, public int $to) {}

    public static function of(?int $from, ?int $to): self
    {
        $now = now()->getTimestamp();

        return new self($from ?? $now - self::DEFAULT_RANGE->durationSeconds(), $to ?? $now);
    }

    /**
     * Ordered, MIN_SPAN to MAX_SPAN, not in the future; too wide is clipped from the front. Null unless both ends are given.
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

    /** Bucket width and label precision key off it. */
    public function range(): ChartRange
    {
        return ChartRange::forSpan($this->span());
    }
}

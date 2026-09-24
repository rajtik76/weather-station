<?php

declare(strict_types=1);

namespace App\ValueObject;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;

/**
 * A stored epoch as the page prints it. Storage stays UTC; only the
 * presentation shifts to the station's own zone.
 */
final readonly class LocalTime
{
    public const string TIMEZONE = 'Europe/Prague';

    private const string STAMP_FORMAT = 'j.n.Y H:i';

    private function __construct(public int $timestamp) {}

    public static function of(int $timestamp): self
    {
        return new self($timestamp);
    }

    /** The one date format on the page; `formatStamp()` in station-charts.js mirrors it. */
    public function stamp(): string
    {
        return $this->moment()->format(self::STAMP_FORMAT);
    }

    /** The station's clock drifts and may stamp ahead of the server; "4 minutes from now" reads as broken. */
    public function ago(): string
    {
        return $this->timestamp > now()->getTimestamp()
            ? 'just now'
            : $this->moment()->diffForHumans();
    }

    /**
     * @return array{at: string, ago: string}
     */
    public function forHumans(): array
    {
        return ['at' => $this->stamp(), 'ago' => $this->ago()];
    }

    /**
     * Epoch as milliseconds of local wall-clock time. The chart reads its
     * axis as UTC, so this is what makes ticks and tooltips print local time
     * whatever the viewer's clock. Not an instant: never compare with now()
     * or convert again. Rows carry the real epoch beside it.
     */
    public function wallClockMs(): int
    {
        return ($this->timestamp + $this->moment()->utcOffset() * 60) * 1000;
    }

    private function moment(): CarbonInterface
    {
        return Date::createFromTimestamp($this->timestamp, self::TIMEZONE);
    }
}

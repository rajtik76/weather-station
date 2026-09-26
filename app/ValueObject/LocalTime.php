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

    private const string CLOCK_FORMAT = 'H:i';

    private const string DATE_FORMAT = 'j.n.Y';

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

    /** Time of day alone, for a column that sits under a full stamp - like an axis tick. */
    public function clock(): string
    {
        return $this->moment()->format(self::CLOCK_FORMAT);
    }

    /** The day alone, for a figure that covers the whole of it: the stamp without its time. */
    public function date(): string
    {
        return $this->moment()->format(self::DATE_FORMAT);
    }

    /**
     * Every local day from this one's through $until's, each as its first
     * moment; a day of 23 or 25 hours is still one.
     *
     * @return list<self>
     */
    public function daysThrough(self $until): array
    {
        $days = [];
        $last = $until->moment()->startOfDay();

        for ($day = $this->moment()->startOfDay(); $day->lessThanOrEqualTo($last); $day = $day->addDay()) {
            $days[] = new self($day->getTimestamp());
        }

        return $days;
    }

    /** Hour of the local day, 0-23. */
    public function hour(): int
    {
        return $this->moment()->hour;
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

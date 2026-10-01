<?php

declare(strict_types=1);

namespace App\ValueObject;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;

/**
 * A stored epoch as the page prints it; storage stays UTC.
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

    /** Mirrored by `formatStamp()` in station-charts.js. */
    public function stamp(): string
    {
        return $this->moment()->format(self::STAMP_FORMAT);
    }

    public function clock(): string
    {
        return $this->moment()->format(self::CLOCK_FORMAT);
    }

    public function date(): string
    {
        return $this->moment()->format(self::DATE_FORMAT);
    }

    /**
     * Local days through $until, each as its first moment; a 23 or 25 hour day is still one.
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

    public function hour(): int
    {
        return $this->moment()->hour;
    }

    /** The station's clock may stamp ahead of the server; "4 minutes from now" reads as broken. */
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
     * Milliseconds of local wall-clock time, because the chart reads its axis as UTC. Not an instant: never compare with now() or convert again.
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

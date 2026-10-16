<?php

declare(strict_types=1);

namespace App\ValueObject;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final readonly class LocalTime
{
    public const string TIMEZONE = 'Europe/Prague';

    private const string STAMP_FORMAT = 'j.n.Y H:i';

    private const string DAY_FORMAT = 'j.n.Y';

    private function __construct(public int $timestamp) {}

    public static function of(int $timestamp): self
    {
        return new self($timestamp);
    }

    public function stamp(): string
    {
        return $this->moment()->format(self::STAMP_FORMAT);
    }

    public function day(): string
    {
        return $this->moment()->format(self::DAY_FORMAT);
    }

    public function ago(): string
    {
        return $this->timestamp > CarbonImmutable::now()->getTimestamp()
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

    public function wallClockMs(): int
    {
        return ($this->timestamp + $this->moment()->utcOffset() * 60) * 1000;
    }

    private function moment(): CarbonInterface
    {
        return CarbonImmutable::createFromTimestamp($this->timestamp, self::TIMEZONE);
    }
}

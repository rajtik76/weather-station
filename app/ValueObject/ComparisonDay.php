<?php

declare(strict_types=1);

namespace App\ValueObject;

use Carbon\CarbonImmutable;

final readonly class ComparisonDay
{
    public const int HISTORY_DAYS = 30;

    private function __construct(public string $date, public int $from, public int $until) {}

    public static function today(): string
    {
        return CarbonImmutable::now(LocalTime::TIMEZONE)->toDateString();
    }

    public static function earliest(): string
    {
        return CarbonImmutable::now(LocalTime::TIMEZONE)->subDays(self::HISTORY_DAYS)->toDateString();
    }

    public static function of(?string $date): self
    {
        $valid = is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) === 1;
        $moment = $valid ? CarbonImmutable::createFromFormat('!Y-m-d', $date, LocalTime::TIMEZONE) : null;
        $normalised = $moment instanceof CarbonImmutable && $moment->toDateString() === $date
            ? max(self::earliest(), min(self::today(), $date)) : self::today();
        $start = CarbonImmutable::parse($normalised, LocalTime::TIMEZONE);

        return new self($normalised, $start->getTimestamp(), $start->addDay()->getTimestamp());
    }

    public function shift(int $days): string
    {
        return self::of(CarbonImmutable::parse($this->date, LocalTime::TIMEZONE)->addDays(max(-1, min(1, $days)))->toDateString())->date;
    }
}

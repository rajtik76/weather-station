<?php

declare(strict_types=1);

namespace App\ValueObject;

use UnexpectedValueException;

/**
 * The hero readouts off the trailing day. Day min and max come off the entries' extremes, not their means.
 *
 * @phpstan-type Figures array{now: float, delta: float, dayMin: float, dayMax: float, trace: non-empty-list<float>}
 *
 * @phpstan-import-type DayRow from Readout
 */
final readonly class DayFigures
{
    /**
     * @param  non-empty-list<DayRow>  $day  oldest first
     */
    private function __construct(private array $day) {}

    /**
     * @param  list<DayRow>  $day  oldest first
     */
    public static function of(array $day): self
    {
        if ($day === []) {
            throw new UnexpectedValueException('No readings to summarise.');
        }

        return new self($day);
    }

    /**
     * Noise (`n`) and light (`l`) only when the day holds some.
     *
     * @return array<string, Figures>
     */
    public function metrics(): array
    {
        return array_filter([
            't' => $this->figures('t'),
            'h' => $this->figures('h'),
            'p' => $this->figures('p'),
            'n' => $this->noise(),
            'l' => $this->light(),
        ]);
    }

    /**
     * @param  't'|'h'|'p'  $field
     * @return Figures
     */
    private function figures(string $field): array
    {
        return $this->summary(array_column($this->day, $field), array_column($this->day, "{$field}Min"), array_column($this->day, "{$field}Max"));
    }

    /**
     * A window has no quietest sample, and LAmax is a single door slam, not the loudest ten minutes.
     *
     * @return Figures|null
     */
    private function noise(): ?array
    {
        $levels = [];

        foreach ($this->day as $entry) {
            if ($entry['n'] !== null) {
                $levels[] = $entry['n'];
            }
        }

        return $levels === [] ? null : $this->summary($levels, $levels, $levels);
    }

    /**
     * The 24 h max is the brightest sample, not the brightest mean.
     *
     * @return Figures|null
     */
    private function light(): ?array
    {
        $lit = [];

        foreach ($this->day as $entry) {
            if ($entry['l'] !== null) {
                $lit[] = [$entry['l'], $entry['lMin'] ?? $entry['l'], $entry['lMax'] ?? $entry['l']];
            }
        }

        if ($lit === []) {
            return null;
        }

        return $this->summary(array_column($lit, 0), array_column($lit, 1), array_column($lit, 2));
    }

    /**
     * @param  non-empty-list<float>  $day
     * @param  non-empty-list<float>  $lows
     * @param  non-empty-list<float>  $highs
     * @return Figures
     */
    private function summary(array $day, array $lows, array $highs): array
    {
        $now = end($day);

        return [
            'now' => $now,
            // One hour ago (six slots), or the oldest point if the day is shorter.
            'delta' => $now - (float) $day[max(0, count($day) - 7)],
            'dayMin' => min($lows),
            'dayMax' => max($highs),
            'trace' => $day,
        ];
    }
}

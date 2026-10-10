<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Enums\RaceBlock;
use App\Models\Forecast;

/**
 * The model race over a run of days: per block the entrant with the fewest summed points leads.
 *
 * @phpstan-import-type Horizon from Forecast
 *
 * @phpstan-type Standing array{name: string, total: ?float, wins: int}
 */
final readonly class RaceStandings
{
    /**
     * @param  list<RaceDay>  $days  oldest first
     */
    public function __construct(public array $days) {}

    /**
     * Every entrant, leader first, a tie in RaceEntrants::NAMES order; entrants without points follow, RaceEntrants::FALLBACK first.
     *
     * @return list<Standing>
     */
    public function table(RaceBlock $block): array
    {
        $totals = [];
        $wins = array_fill_keys(RaceEntrants::NAMES, 0);

        foreach ($this->days as $day) {
            foreach ($day->points[$block->value] ?? [] as $name => $points) {
                $totals[$name] = ($totals[$name] ?? 0.0) + $points;
            }

            $winner = $day->winner($block);

            if ($winner !== null) {
                $wins[$winner] = ($wins[$winner] ?? 0) + 1;
            }
        }

        $order = array_flip(RaceEntrants::NAMES);
        $rank = fn (string $name): array => isset($totals[$name])
            ? [$totals[$name], $order[$name] ?? PHP_INT_MAX]
            : [INF, $name === RaceEntrants::FALLBACK ? -1 : $order[$name] ?? PHP_INT_MAX];
        $names = array_keys([...$wins, ...$totals]);
        usort($names, fn (string $a, string $b): int => $rank($a) <=> $rank($b));

        return array_map(fn (string $name): array => ['name' => $name, 'total' => $totals[$name] ?? null, 'wins' => $wins[$name] ?? 0], $names);
    }

    /**
     * Each horizon shows the band of the best-placed entrant that answered it, in the block of its target.
     *
     * @param  list<Horizon>  $horizons
     * @return list<Horizon>
     */
    public function pick(array $horizons, int $issuedAt): array
    {
        $orders = [];

        foreach (RaceBlock::cases() as $block) {
            $orders[$block->value] = array_column($this->table($block), 'name');
        }

        return array_map(function (array $horizon) use ($issuedAt, $orders): array {
            $bands = RaceEntrants::bands($horizon);
            $block = RaceBlock::at($issuedAt + $horizon['hours'] * 3600);
            $name = array_find($orders[$block->value], fn (string $name): bool => isset($bands[$name]));

            return $name === null ? $horizon : [...$horizon, 'temperature' => $bands[$name], 'shown_by' => $name];
        }, $horizons);
    }
}

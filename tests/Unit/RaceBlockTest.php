<?php

declare(strict_types=1);

use App\Enums\RaceBlock;

it('puts a local hour in its part of the day', function (int $hour, RaceBlock $block): void {
    expect(RaceBlock::ofHour($hour))->toBe($block);
})->with([
    'midnight' => [0, RaceBlock::Night],
    'last hour of the night' => [5, RaceBlock::Night],
    'first hour of the morning' => [6, RaceBlock::Morning],
    'last hour of the morning' => [11, RaceBlock::Morning],
    'noon' => [12, RaceBlock::Day],
    'last hour of the evening' => [21, RaceBlock::Day],
    'first hour of the night' => [22, RaceBlock::Night],
]);

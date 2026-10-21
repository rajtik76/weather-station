<?php

declare(strict_types=1);

use App\ValueObject\RainSpell;

/**
 * @param  list<?bool>  $rain
 * @return list<array{timestamp: int, rain: ?bool}>
 */
function rainWindows(array $rain): array
{
    $start = strtotime('2026-10-21T16:39:00Z');

    return array_map(fn (?bool $heard, int $index): array => ['timestamp' => $start + $index * 600, 'rain' => $heard], $rain, array_keys($rain));
}

it('runs from the first to the latest rainy window, across pauses shorter than the dry spell', function (): void {
    expect(RainSpell::current(rainWindows([false, true, true]), 3)?->span())->toBe('18:39-18:59')
        ->and(RainSpell::current(rainWindows([true, true, false, false]), 3)?->span())->toBe('18:29-18:49')
        ->and(RainSpell::current(rainWindows([true, false, false, true]), 3)?->span())->toBe('18:29-19:09')
        ->and(RainSpell::current(rainWindows([true, false, false, false, true]), 3)?->span())->toBe('19:09-19:19')
        ->and(RainSpell::current(rainWindows([true, null, null]), 3)?->span())->toBe('18:29-18:39');
});

it('is over after the dry spell, and absent without rain', function (): void {
    expect(RainSpell::current(rainWindows([true, false, false, false]), 3))->toBeNull()
        ->and(RainSpell::current(rainWindows([false, false]), 3))->toBeNull()
        ->and(RainSpell::current([], 3))->toBeNull();
});

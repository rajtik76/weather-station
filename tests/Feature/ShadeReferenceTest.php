<?php

declare(strict_types=1);

use App\ValueObject\LocalTime;
use App\ValueObject\ShadeReference;
use App\ValueObject\ShieldSample;
use App\ValueObject\SolarPosition;
use Illuminate\Support\Facades\Date;

/**
 * Shade before 08:00 (+1 °C over Mikulka, 100 lx), sun on the shield 08:00-11:00 (+5 °C), shade after (-1 °C, 800 lx).
 *
 * @return list<array{sample: ShieldSample, reference: float, reference_at: int}>
 */
function shadedMorning(string $until = '13:00', float $shadedLux = 100.0): array
{
    $pairs = [];
    $at = fn (string $time): int => Date::parse('2026-10-09 '.$time, LocalTime::TIMEZONE)->getTimestamp();

    for ($timestamp = $at('06:00'); $timestamp <= $at($until); $timestamp += 600) {
        $facing = $timestamp >= $at('08:00') && $timestamp <= $at('11:00');
        $offset = $facing ? 5.0 : ($timestamp < $at('08:00') ? 1.0 : -1.0);
        $lux = $facing ? 2000.0 : ($timestamp < $at('08:00') ? $shadedLux : 800.0);
        $pairs[] = ['sample' => new ShieldSample($timestamp, 10.0 + $offset, $lux, new SolarPosition(20.0, 120.0), $facing, []), 'reference' => 10.0, 'reference_at' => $timestamp];
    }

    return $pairs;
}

it('shifts sun-facing references by the shade offset interpolated between the anchors', function (): void {
    $anchored = array_column(array_map(fn (array $pair): array => [$pair['sample']->timestamp, $pair['reference']], ShadeReference::anchor(shadedMorning())), 1, 0);
    $at = fn (string $time): int => Date::parse('2026-10-09 '.$time, LocalTime::TIMEZONE)->getTimestamp();

    expect($anchored)->toHaveCount(43)
        ->and($anchored[$at('09:30')])->toEqualWithDelta(10.0 + 1.0 - 2.0 * 125 / 260, 0.0001)
        ->and($anchored[$at('08:00')])->toEqualWithDelta(10.0 + 1.0 - 2.0 * 35 / 260, 0.0001)
        ->and($anchored[$at('11:30')])->toEqualWithDelta(10.0 + 1.0 - 2.0 * 245 / 260, 0.0001)
        ->and($anchored[$at('12:00')])->toBe(9.0)
        ->and($anchored[$at('07:00')])->toBe(10.0)
        ->and($anchored[$at('12:10')])->toBe(10.0);
});

it('leaves out the window of a day without the anchor after the sun', function (): void {
    $anchored = ShadeReference::anchor(shadedMorning(until: '11:20'));

    expect(array_filter($anchored, fn (array $pair): bool => $pair['sample']->timestamp >= Date::parse('2026-10-09 08:00', LocalTime::TIMEZONE)->getTimestamp()))->toBeEmpty()
        ->and($anchored)->toHaveCount(12);
});

it('does not anchor on bright readings before the sun reaches the shield', function (): void {
    $anchored = ShadeReference::anchor(shadedMorning(shadedLux: 400.0));

    expect(array_filter($anchored, fn (array $pair): bool => $pair['sample']->facesSun))->toBeEmpty();
});

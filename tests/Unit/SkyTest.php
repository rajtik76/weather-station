<?php

declare(strict_types=1);

use App\ValueObject\Sky;

/** Epoch of a UTC moment. */
function skyAt(string $utc): int
{
    return new DateTimeImmutable($utc, new DateTimeZone('UTC'))->getTimestamp();
}

it('follows the real sunrise and sunset over the station', function (string $utc, bool $daylight): void {
    expect(Sky::isDaylight(skyAt($utc)))->toBe($daylight);
})->with([
    // Prague time is UTC+2 in June and UTC+1 in December.
    'midsummer, before dawn at 02:30' => ['2026-06-21 00:30:00', false],
    'midsummer, 07:00 is long up' => ['2026-06-21 05:00:00', true],
    'midsummer, light at 22:00 is gone' => ['2026-06-21 20:00:00', false],
    'midsummer, light at 20:00' => ['2026-06-21 18:00:00', true],
    'midwinter, 07:00 is still dark' => ['2026-12-21 06:00:00', false],
    'midwinter, noon' => ['2026-12-21 11:00:00', true],
    'midwinter, 17:00 is dark again' => ['2026-12-21 16:00:00', false],
]);

it('switches at sunrise and sunset, which fall at 04:55 and 17:00 UTC on 24 September', function (string $utc, bool $daylight): void {
    expect(Sky::isDaylight(skyAt($utc)))->toBe($daylight);
})->with([
    'just before sunrise' => ['2026-09-24 04:53:00', false],
    'just after sunrise' => ['2026-09-24 04:58:00', true],
    'just before sunset' => ['2026-09-24 16:58:00', true],
    'just after sunset' => ['2026-09-24 17:03:00', false],
]);

it('draws the rain chance on its thresholds, sun or moon when dry', function (int $rain, array $picture): void {
    // Noon and midnight UTC in September are day and night over the station.
    expect(Sky::forHour(skyAt('2026-09-24 10:00:00'), $rain))->toBe($picture['day'])
        ->and(Sky::forHour(skyAt('2026-09-24 22:00:00'), $rain))->toBe($picture['night']);
})->with([
    'dry' => [9, [
        'day' => ['icon' => 'sun', 'label' => 'dry', 'tone' => 'day'],
        'night' => ['icon' => 'moon', 'label' => 'dry', 'tone' => 'night'],
    ]],
    'a slight chance' => [10, [
        'day' => ['icon' => 'cloud-sun', 'label' => 'slight chance of rain', 'tone' => 'day'],
        'night' => ['icon' => 'cloud-moon', 'label' => 'slight chance of rain', 'tone' => 'night'],
    ]],
    'just under possible' => [29, [
        'day' => ['icon' => 'cloud-sun', 'label' => 'slight chance of rain', 'tone' => 'day'],
        'night' => ['icon' => 'cloud-moon', 'label' => 'slight chance of rain', 'tone' => 'night'],
    ]],
    'rain possible' => [30, [
        'day' => ['icon' => 'cloud-drizzle', 'label' => 'rain possible', 'tone' => 'rain'],
        'night' => ['icon' => 'cloud-drizzle', 'label' => 'rain possible', 'tone' => 'rain'],
    ]],
    'just under likely' => [59, [
        'day' => ['icon' => 'cloud-drizzle', 'label' => 'rain possible', 'tone' => 'rain'],
        'night' => ['icon' => 'cloud-drizzle', 'label' => 'rain possible', 'tone' => 'rain'],
    ]],
    'rain likely' => [60, [
        'day' => ['icon' => 'cloud-rain', 'label' => 'rain likely', 'tone' => 'rain'],
        'night' => ['icon' => 'cloud-rain', 'label' => 'rain likely', 'tone' => 'rain'],
    ]],
]);

it('picks the scene by the next hour\'s rain chance and the time of day', function (?int $rain, ?string $scene): void {
    expect(Sky::scene(skyAt('2026-09-24 10:00:00'), false, $rain))->toBe($scene === null ? null : "{$scene}-day")
        ->and(Sky::scene(skyAt('2026-09-24 22:00:00'), false, $rain))->toBe($scene === null ? null : "{$scene}-night");
})->with([
    'dry' => [0, 'clear'],
    'just under partly' => [9, 'clear'],
    'partly' => [10, 'partly'],
    'just under drizzle' => [29, 'partly'],
    'drizzle' => [30, 'drizzle'],
    'just under rain' => [59, 'drizzle'],
    'rain' => [60, 'rain'],
    'no forecast' => [null, null],
]);

it('puts rain the microphone hears before any forecast, even a missing one', function (?int $rain): void {
    expect(Sky::scene(skyAt('2026-09-24 10:00:00'), true, $rain))->toBe('rain-day')
        ->and(Sky::scene(skyAt('2026-09-24 22:00:00'), true, $rain))->toBe('rain-night');
})->with([
    'forecast dry' => [0],
    'no forecast' => [null],
]);

it('leaves the sky plain when nothing was heard and nothing is forecast', function (): void {
    expect(Sky::scene(skyAt('2026-09-24 10:00:00'), null, null))->toBeNull();
});

<?php

declare(strict_types=1);

use App\ValueObject\SolarPosition;
use App\ValueObject\StationSite;

it('places the equinox Sun near the zenith at equatorial noon', function (): void {
    $sun = SolarPosition::at(strtotime('2026-03-20T12:00:00Z'), 0.0, 0.0);

    expect($sun->elevation)->toBeBetween(87.0, 90.0);
});

it('puts the Sun below the horizon at equatorial midnight', function (): void {
    expect(SolarPosition::at(strtotime('2026-03-20T00:00:00Z'), 0.0, 0.0)->elevation)->toBeLessThan(-85.0);
});

it('illuminates the east balcony in the morning and leaves it shaded in the afternoon', function (): void {
    expect(SolarPosition::at(strtotime('2026-10-01T07:00:00Z'), StationSite::LATITUDE, StationSite::LONGITUDE)->faces(65.0))->toBeTrue()
        ->and(SolarPosition::at(strtotime('2026-10-01T13:00:00Z'), StationSite::LATITUDE, StationSite::LONGITUDE)->faces(65.0))->toBeFalse();
});

it('respects the horizon and the exact edge of a facade', function (float $elevation, float $azimuth, bool $expected): void {
    expect(new SolarPosition($elevation, $azimuth)->faces(65.0))->toBe($expected);
})->with([
    [0.0, 100.0, false],
    [-1.0, 100.0, false],
    [20.0, 154.99, true],
    [20.0, 155.0, false],
    [20.0, 156.0, false],
]);

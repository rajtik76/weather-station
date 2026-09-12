<?php

declare(strict_types=1);

use App\ValueObject\DewPoint;
use App\ValueObject\MeasurementDataV1;

function humidReading(int $temperature, int $humidity): MeasurementDataV1
{
    return new MeasurementDataV1(temperature: $temperature, humidity: $humidity, pressure: 97389);
}

it('derives the dew point from temperature and humidity', function (): void {
    // A mild room at 21,5 °C and 48 %: the air would have to cool to about
    // 10 °C before it condensed on anything.
    expect(DewPoint::of(humidReading(2150, 4800))?->celsius(2))->toBe(10.02);
});

it('meets the temperature at saturation', function (): void {
    expect(DewPoint::of(humidReading(2150, 10000))?->celsius(2))->toBe(21.5);
});

it('stays below the temperature in frost', function (): void {
    expect(DewPoint::of(humidReading(-500, 8000))?->celsius(2))->toBe(-7.92);
});

it('has no answer for a reading of zero humidity', function (): void {
    // The protocol allows 0 %, whose logarithm is minus infinity - and a
    // BME280 reports 0 when its humidity path fails, so it is a hole in the
    // record rather than a figure.
    expect(DewPoint::of(humidReading(3000, 0)))->toBeNull();
});

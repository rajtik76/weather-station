<?php

declare(strict_types=1);

use App\ValueObject\MeasurementDataV1;
use App\ValueObject\SeaLevelPressure;

/** The station's own height, so the figures below are the ones the page shows. */
const ALTITUDE = 345.0;

function reading(int $temperature, int $pressure): MeasurementDataV1
{
    return new MeasurementDataV1(temperature: $temperature, humidity: 5000, pressure: $pressure);
}

it('reduces a station reading to sea level', function (): void {
    // 973,89 hPa at 345 m and 21,34 °C is a little over 1013 at sea level,
    // which is the figure a forecast for Plzeň quotes.
    expect(SeaLevelPressure::reduce(reading(2134, 97389), ALTITUDE)->hectopascals())
        ->toBe(1013.5);
});

it('leaves a reading taken at sea level alone', function (): void {
    expect(SeaLevelPressure::reduce(reading(2134, 97389), 0.0)->hectopascals())
        ->toBe(973.9);
});

it('reduces a cold column more than a warm one', function (): void {
    // Cold air is denser, so the same reading stands for a higher sea level
    // pressure. Assuming a fixed 15 °C instead of measuring would lose this.
    $frost = SeaLevelPressure::reduce(reading(-1000, 97000), ALTITUDE)->hectopascals();
    $heat = SeaLevelPressure::reduce(reading(3500, 97000), ALTITUDE)->hectopascals();

    expect($frost)->toBe(1014.2)
        ->and($heat)->toBe(1007.7)
        ->and($frost)->toBeGreaterThan($heat);
});

<?php

declare(strict_types=1);

use App\Models\Sensor;

it('slugs the name for the URL when the sensor registers', function (): void {
    $sensor = Sensor::factory()->create(['name' => 'Balkón jih / BME280 #2']);

    expect($sensor->slug)->toBe('balkon-jih-bme280-2');
});

it('keeps slugs apart when two names slug alike', function (): void {
    Sensor::factory()->create(['name' => 'Sensor 1']);
    $second = Sensor::factory()->create(['name' => 'sensor-1']);
    $third = Sensor::factory()->create(['name' => 'SENSOR_1']);

    expect($second->slug)->toBe('sensor-1-2')
        ->and($third->slug)->toBe('sensor-1-3');
});

it('gives a name of nothing but symbols a slug all the same', function (): void {
    expect(Sensor::factory()->create(['name' => '###'])->slug)->toBe('sensor');
});

it('keeps a slug given by hand', function (): void {
    expect(Sensor::factory()->create(['name' => 'Sensor 1', 'slug' => 'north'])->slug)->toBe('north');
});

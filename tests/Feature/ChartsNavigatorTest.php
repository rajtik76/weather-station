<?php

declare(strict_types=1);

use App\Enums\ProtocolVersion;
use App\Livewire\Charts;
use App\Models\Measurement;
use App\Models\Sensor;
use App\ValueObject\MeasurementDataV1;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;

it('draws the navigator for a record shorter than one thinning bucket', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    // Drifting stamps, no row near a six-hour boundary: thinning by epoch phase left the navigator empty.
    $sensor = Sensor::factory()->create();

    foreach (range(1, 18) as $slot) {
        Measurement::factory()->for($sensor)->create([
            'timestamp' => now()->subMinutes($slot * 10)->getTimestamp() + 122,
        ]);
    }

    expect(navigatorRows(Livewire::test(Charts::class)->html()))->toHaveCount(18);
});

it('thins the navigator to one point per bucket once the record is long', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    $data = (string) new MeasurementDataV1(temperature: 2150, humidity: 4800, pressure: 97389);
    $sensor = Sensor::factory()->create();

    $rows = collect(range(1, 1584))->map(fn (int $slot): array => [
        'sensor_id' => $sensor->id,
        'timestamp' => now()->subMinutes($slot * 10)->getTimestamp() + 122,
        'protocol_version' => ProtocolVersion::V1->value,
        'data' => $data,
    ]);

    Measurement::insert($rows->all());

    expect(navigatorRows(Livewire::test(Charts::class)->html()))
        ->toHaveCount(45);
});

it('ends the thinned navigator on the newest reading', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    $data = (string) new MeasurementDataV1(temperature: 2150, humidity: 4800, pressure: 97389);
    $sensor = Sensor::factory()->create();
    $newest = now()->subMinutes(10)->getTimestamp() + 122;

    Measurement::insert(collect(range(1, 1584))->map(fn (int $slot): array => [
        'sensor_id' => $sensor->id,
        'timestamp' => now()->subMinutes($slot * 10)->getTimestamp() + 122,
        'protocol_version' => ProtocolVersion::V1->value,
        'data' => $data,
    ])->all());

    Measurement::factory()->create(['timestamp' => now()->getTimestamp()]);

    $epochs = array_column(navigatorRows(Livewire::test(Charts::class)->html()), 5);

    expect($epochs)->toContain($newest)
        ->each->toBeLessThanOrEqual($newest);
});

it('keeps another sensor\'s readings out of the averaging', function (): void {
    $start = Date::parse('2026-03-01 00:00:00', 'UTC');
    $this->travelTo($start->copy()->addDay());

    $shown = Sensor::factory()->create();
    $other = Sensor::factory()->create();

    // The other station is ten degrees colder; averaged together the line would sit five under.
    foreach (range(0, 3) as $bucket) {
        Measurement::factory()->for($other)->create([
            'timestamp' => $start->getTimestamp() + $bucket * 3600,
            'data' => (string) new MeasurementDataV1(temperature: 1000, humidity: 5000, pressure: 97389),
        ]);
        Measurement::factory()->for($shown)->create([
            'timestamp' => $start->getTimestamp() + $bucket * 3600 + 60,
            'data' => (string) new MeasurementDataV1(temperature: 2000, humidity: 5000, pressure: 97389),
        ]);
    }

    $month = filledBuckets(Livewire::test(Charts::class)
        ->call('zoomTo', $start->getTimestamp() - 20 * 86400, now()->getTimestamp())
        ->html());

    expect(array_column($month, 1))->toEqual([20, 20, 20, 20]);
});

<?php

declare(strict_types=1);

use App\Models\StationReport;
use App\ValueObject\BoardReport;
use Illuminate\Support\Facades\Date;

/**
 * A board's `station` object, as firmware 2.3 sends it, without the clock figures.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function boardReportData(array $overrides = []): array
{
    return [
        'firmware' => '2.3.0',
        'board' => 'ESP32C3_DEV',
        'reset_reason' => 'power on',
        'uptime' => 273_600,
        'heap_free' => 200_000,
        'heap_min' => 150_000,
        'rssi' => -62,
        'wifi_network' => 0,
        'wifi_switches' => 2,
        'buffered' => 1,
        'upload_failures' => 3,
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $data
 */
function boardReportArrivedAt(array $data, string $utc = '2026-09-24 08:00:00'): StationReport
{
    $report = new StationReport(['data' => $data]);
    $report->created_at = Date::parse($utc, 'UTC');

    return $report;
}

beforeEach(function (): void {
    $this->travelTo(Date::parse('2026-09-24 08:05:00', 'UTC'));
});

it('reports the board in the words the page prints, dated by its arrival', function (): void {
    $report = BoardReport::of(boardReportArrivedAt(boardReportData()))->toArray();

    expect($report)->toBe([
        'firmware' => '2.3.0',
        'board' => 'ESP32C3_DEV',
        'resetReason' => 'power on',
        'uptime' => '3 d 4 h',
        'network' => 'primary',
        'rssi' => -62,
        'switches' => 2,
        'heapFree' => 200_000,
        'heapMin' => 150_000,
        'buffered' => 1,
        'uploadFailures' => 3,
        'clockDrift' => null,
        'clockDriftWorst' => null,
        'clockSynced' => null,
        'at' => '24.9.2026 10:00',
        'ago' => '5 minutes ago',
    ]);
});

it('says just now for a report stamped ahead of the server', function (): void {
    $report = BoardReport::of(boardReportArrivedAt(boardReportData(), '2026-09-24 08:10:00'))->toArray();

    expect($report['ago'])->toBe('just now');
});

it('writes the uptime in its two largest units', function (int $seconds, string $expected): void {
    $report = BoardReport::of(boardReportArrivedAt(boardReportData(['uptime' => $seconds])))->toArray();

    expect($report['uptime'])->toBe($expected);
})->with([
    'days and hours' => [273_600, '3 d 4 h'],
    'a day to the hour' => [86_400, '1 d 0 h'],
    'hours and minutes' => [15_120, '4 h 12 min'],
    'minutes and seconds' => [725, '12 min 5 s'],
    'under a minute' => [59, '0 min 59 s'],
]);

it('names the network the board is on', function (int $network, string $expected): void {
    $report = BoardReport::of(boardReportArrivedAt(boardReportData(['wifi_network' => $network])))->toArray();

    expect($report['network'])->toBe($expected);
})->with([
    'the first one' => [0, 'primary'],
    'the second one' => [1, 'backup'],
]);

it('has no board before firmware 2.3', function (): void {
    $data = boardReportData();
    unset($data['board']);

    expect(BoardReport::of(boardReportArrivedAt($data))->toArray()['board'])->toBeNull();
});

it('reports the clock drift with its sync time once the board has re-synced', function (): void {
    $data = boardReportData([
        'clock_step_ms' => 812,
        'clock_step_over_s' => 3_600,
        'clock_step_max_ms' => -1_204,
        'clock_synced_at' => Date::parse('2026-09-24 07:40:00', 'UTC')->getTimestamp(),
    ]);

    $report = BoardReport::of(boardReportArrivedAt($data))->toArray();

    expect($report)->toMatchArray([
        'clockDrift' => '+812 ms in 1 h 0 min',
        'clockDriftWorst' => '−1 204 ms',
        'clockSynced' => '24.9.2026 09:40',
    ]);
});

it('has no drift figures until the board has re-synced once since boot', function (array $clock): void {
    $report = BoardReport::of(boardReportArrivedAt(boardReportData([
        'clock_step_ms' => 812,
        'clock_step_max_ms' => 900,
        ...$clock,
    ])))->toArray();

    expect($report)->toMatchArray(['clockDrift' => null, 'clockDriftWorst' => null, 'clockSynced' => null]);
})->with([
    'no measuring span reported' => [['clock_synced_at' => 1_790_000_000]],
    'a span of zero' => [['clock_step_over_s' => 0, 'clock_synced_at' => 1_790_000_000]],
    'no sync time reported' => [['clock_step_over_s' => 3_600]],
    'a sync time of zero' => [['clock_step_over_s' => 3_600, 'clock_synced_at' => 0]],
]);

it('signs the drift and groups its thousands with a space', function (int $milliseconds, string $expected): void {
    $report = BoardReport::of(boardReportArrivedAt(boardReportData([
        'clock_step_ms' => $milliseconds,
        'clock_step_over_s' => 60,
        'clock_synced_at' => 1_790_000_000,
    ])))->toArray();

    expect($report['clockDrift'])->toBe("{$expected} in 1 min 0 s");
})->with([
    'ahead' => [812, '+812 ms'],
    'behind' => [-1_204, '−1 204 ms'],
    'spot on' => [0, '0 ms'],
    'over a million' => [1_234_567, '+1 234 567 ms'],
]);

it('takes the worst step from the drift itself when the board reports no maximum', function (): void {
    $report = BoardReport::of(boardReportArrivedAt(boardReportData([
        'clock_step_ms' => -350,
        'clock_step_over_s' => 3_600,
        'clock_synced_at' => 1_790_000_000,
    ])))->toArray();

    expect($report['clockDriftWorst'])->toBe('−350 ms');
});

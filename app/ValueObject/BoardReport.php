<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Models\StationReport;

/**
 * A `station` object as the board sent it, in the words the page prints,
 * dated by arrival: the report describes the board at the moment it
 * uploaded. Nothing is derived on the server; if a figure looks wrong, the
 * firmware sent it wrong. SSID and IP stay off: the page is public.
 *
 * @phpstan-type Report array{firmware: string, board: string|null, resetReason: string, uptime: string, network: string, rssi: int, switches: int, heapFree: int, heapMin: int, buffered: int, uploadFailures: int, clockDrift: string|null, clockDriftWorst: string|null, clockSynced: string|null, at: string, ago: string}
 */
final readonly class BoardReport
{
    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(private array $data, private int $arrivedAt) {}

    public static function of(StationReport $report): self
    {
        return new self($report->data, $report->created_at?->getTimestamp() ?? 0);
    }

    /**
     * @return Report
     */
    public function toArray(): array
    {
        $data = $this->data;

        return [
            'firmware' => (string) $data['firmware'],
            // Null before firmware 2.3, which is when the board began reporting it.
            'board' => isset($data['board']) ? (string) $data['board'] : null,
            'resetReason' => (string) $data['reset_reason'],
            'uptime' => $this->duration((int) $data['uptime']),
            'network' => (int) $data['wifi_network'] === 0 ? 'primary' : 'backup',
            'rssi' => (int) $data['rssi'],
            'switches' => (int) $data['wifi_switches'],
            'heapFree' => (int) $data['heap_free'],
            'heapMin' => (int) $data['heap_min'],
            'buffered' => (int) $data['buffered'],
            'uploadFailures' => (int) $data['upload_failures'],
            ...$this->clockDrift(),
            ...LocalTime::of($this->arrivedAt)->forHumans(),
        ];
    }

    /**
     * Nulls until the board has re-synced once since boot: the boot sync steps
     * from 1970 and says nothing about the crystal.
     *
     * @return array{clockDrift: string|null, clockDriftWorst: string|null, clockSynced: string|null}
     */
    private function clockDrift(): array
    {
        $data = $this->data;
        $overSeconds = (int) ($data['clock_step_over_s'] ?? 0);
        $syncedAt = (int) ($data['clock_synced_at'] ?? 0);

        if ($overSeconds <= 0 || $syncedAt <= 0) {
            return ['clockDrift' => null, 'clockDriftWorst' => null, 'clockSynced' => null];
        }

        return [
            'clockDrift' => $this->signedMilliseconds((int) $data['clock_step_ms']).' in '.$this->duration($overSeconds),
            'clockDriftWorst' => $this->signedMilliseconds((int) ($data['clock_step_max_ms'] ?? $data['clock_step_ms'])),
            'clockSynced' => LocalTime::of($syncedAt)->stamp(),
        ];
    }

    /** "+812 ms", "−1 204 ms", "0 ms". */
    private function signedMilliseconds(int $milliseconds): string
    {
        return Figure::signed($milliseconds, 0, plusOnZero: false).' ms';
    }

    /** "3 d 4 h", "4 h 12 min", "12 min 5 s". */
    private function duration(int $seconds): string
    {
        $days = intdiv($seconds, 86_400);
        $hours = intdiv($seconds % 86_400, 3_600);
        $minutes = intdiv($seconds % 3_600, 60);

        return match (true) {
            $days > 0 => "{$days} d {$hours} h",
            $hours > 0 => "{$hours} h {$minutes} min",
            default => "{$minutes} min ".($seconds % 60).' s',
        };
    }
}

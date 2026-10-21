<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Measurement;
use App\ValueObject\Readout;

/** What the detector makes of a station's newest noise window. */
final readonly class RainHearing
{
    /**
     * @return ?array{timestamp: int, heard: bool}
     */
    public function newest(int $sensorId, int $now): ?array
    {
        $measurement = new StationRecord($sensorId)->newest();

        if (! $measurement instanceof Measurement) {
            return null;
        }

        $heard = $this->heard($measurement, $now);

        return $heard === null ? null : ['timestamp' => $measurement->timestamp, 'heard' => $heard];
    }

    /** Null for a silent station or a window without a spectrum, never false. */
    public function heard(?Measurement $measurement, int $now): ?bool
    {
        if (! $measurement instanceof Measurement || $measurement->timestamp < $now - StationRecord::SILENT_AFTER_SECONDS) {
            return null;
        }

        return Readout::of($measurement->data)->rain();
    }
}

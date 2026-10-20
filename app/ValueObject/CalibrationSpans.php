<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Models\TemperatureCalibration;

final readonly class CalibrationSpans
{
    /**
     * A newer accepted calibration claims everything from its validation end on.
     *
     * @param  iterable<TemperatureCalibration>  $calibrations  Newest first; unaccepted ones are skipped.
     * @return array<int, array{from: int, until: ?int}> By id, only calibrations that correct some time; until null = open.
     */
    public static function of(iterable $calibrations): array
    {
        $spans = [];
        $claimedFrom = null;

        foreach ($calibrations as $calibration) {
            if (! $calibration->accepted) {
                continue;
            }

            if ($claimedFrom === null || $calibration->validated_until < $claimedFrom) {
                $spans[$calibration->id] = ['from' => $calibration->validated_until, 'until' => $claimedFrom];
            }

            $claimedFrom = min($claimedFrom ?? PHP_INT_MAX, $calibration->validated_until);
        }

        return $spans;
    }
}

<?php

declare(strict_types=1);

namespace App\ValueObject;

/**
 * An entry that may carry the microphone's window (V3 onwards). Read the
 * noise through this, not a version class, so a later protocol keeps its
 * noise on the dashboard.
 */
interface CarriesNoise extends MeasurementData
{
    public ?NoiseWindow $noise { get; }
}

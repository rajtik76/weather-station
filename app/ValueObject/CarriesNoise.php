<?php

declare(strict_types=1);

namespace App\ValueObject;

/**
 * An entry that may carry the microphone's window (V3 onwards); read it through this, not a version class.
 */
interface CarriesNoise extends MeasurementData
{
    public ?NoiseWindow $noise { get; }
}

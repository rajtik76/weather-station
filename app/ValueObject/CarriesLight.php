<?php

declare(strict_types=1);

namespace App\ValueObject;

/**
 * An entry that may carry the light sensor's window (V4 onwards); read it through this, not a version class.
 */
interface CarriesLight extends MeasurementData
{
    public ?LightWindow $light { get; }
}

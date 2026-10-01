<?php

declare(strict_types=1);

namespace App\ValueObject;

/**
 * An entry that may carry the light sensor's window (V4 onwards). Read the
 * light through this, not a version class, so a later protocol keeps its
 * light on the dashboard.
 */
interface CarriesLight extends MeasurementData
{
    public ?LightWindow $light { get; }
}

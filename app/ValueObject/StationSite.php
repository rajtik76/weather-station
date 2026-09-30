<?php

declare(strict_types=1);

namespace App\ValueObject;

/**
 * Where the station stands, Plzeň-Slovany: one place for the map, the
 * sunrise over the sky, the forecast's solar time and the sea-level
 * reduction, so moving the station is one edit.
 */
final readonly class StationSite
{
    public const float LATITUDE = 49.733242;

    public const float LONGITUDE = 13.399911;

    /** Station height for the sea-level reduction. */
    public const float ALTITUDE_METRES = 345.0;
}

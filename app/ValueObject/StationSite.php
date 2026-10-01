<?php

declare(strict_types=1);

namespace App\ValueObject;

/**
 * Where the station stands, Plzeň-Slovany: one place to edit when it moves.
 */
final readonly class StationSite
{
    public const float LATITUDE = 49.733242;

    public const float LONGITUDE = 13.399911;

    public const float ALTITUDE_METRES = 345.0;
}

<?php

declare(strict_types=1);

namespace App\Enums;

/** How hard the rain heard right now falls; the value is the hero's `data-rain`. */
enum RainIntensity: string
{
    case Light = 'light';
    case Heavy = 'heavy';
}

<?php

declare(strict_types=1);

namespace App\Enums;

/** Wind heard in the shield right now; the value is the hero's `data-wind`. */
enum WindIntensity: string
{
    case Breeze = 'breeze';
    case Strong = 'strong';
}

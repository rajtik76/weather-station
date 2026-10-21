<?php

declare(strict_types=1);

namespace App\Enums;

enum RainReportSource: string
{
    /** Detector state change, answered via buttons. */
    case Detector = 'detector';

    /** Unprompted /rain or /dry. */
    case Observer = 'observer';
}

<?php

declare(strict_types=1);

namespace App\ValueObject;

/**
 * One whole hour of the shown forecast: temperatures in °C, rain chance in whole percent.
 */
final readonly class ForecastHour
{
    /**
     * @param  int  $hours  whole hours after the start of the clock hour the forecasts were issued in
     * @param  int  $at  epoch of the hour
     */
    public function __construct(
        public int $hours,
        public int $at,
        public string $clock,
        public float $t,
        public float $tLow,
        public float $tHigh,
        public int $rain,
    ) {}
}

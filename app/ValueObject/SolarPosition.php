<?php

declare(strict_types=1);

namespace App\ValueObject;

final readonly class SolarPosition
{
    public function __construct(public float $elevation, public float $azimuth) {}

    public static function at(int $timestamp, float $latitude, float $longitude): self
    {
        $hours = ((int) gmdate('G', $timestamp)) + ((int) gmdate('i', $timestamp)) / 60 + ((int) gmdate('s', $timestamp)) / 3600;
        $yearDays = gmdate('L', $timestamp) === '1' ? 366 : 365;
        $yearAngle = 2 * M_PI / $yearDays * ((int) gmdate('z', $timestamp) + ($hours - 12) / 24);
        $equation = 229.18 * (0.000075 + 0.001868 * cos($yearAngle) - 0.032077 * sin($yearAngle)
            - 0.014615 * cos(2 * $yearAngle) - 0.040849 * sin(2 * $yearAngle));
        $declination = 0.006918 - 0.399912 * cos($yearAngle) + 0.070257 * sin($yearAngle)
            - 0.006758 * cos(2 * $yearAngle) + 0.000907 * sin(2 * $yearAngle)
            - 0.002697 * cos(3 * $yearAngle) + 0.00148 * sin(3 * $yearAngle);
        $hourAngle = deg2rad(($hours * 60 + $equation + 4 * $longitude) / 4 - 180);
        $latitudeRadians = deg2rad($latitude);
        $sineElevation = sin($latitudeRadians) * sin($declination) + cos($latitudeRadians) * cos($declination) * cos($hourAngle);
        $azimuth = rad2deg(atan2(sin($hourAngle), cos($hourAngle) * sin($latitudeRadians) - tan($declination) * cos($latitudeRadians))) + 180;

        return new self(rad2deg(asin(max(-1.0, min(1.0, $sineElevation)))), $azimuth);
    }

    public function faces(float $wallAzimuth): bool
    {
        $difference = fmod($this->azimuth - $wallAzimuth + 540, 360) - 180;

        return $this->elevation > 0 && abs($difference) < 90;
    }
}

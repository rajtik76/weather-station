<?php

declare(strict_types=1);

namespace App\ValueObject;

/**
 * The sky over the station as the page draws it. The models forecast rain,
 * not cloud, so the pictures follow the rain chance alone; sun or moon by
 * the real sunrise over the station.
 */
final readonly class Sky
{
    /** Between the real sunrise and sunset over the station. */
    public static function isDaylight(int $timestamp): bool
    {
        $sun = date_sun_info($timestamp, StationSite::LATITUDE, StationSite::LONGITUDE);

        return $timestamp >= (int) $sun['sunrise'] && $timestamp < (int) $sun['sunset'];
    }

    /**
     * The picture over a forecast hour.
     *
     * @return array{icon: string, label: string, tone: string}
     */
    public static function forHour(int $timestamp, int $rain): array
    {
        $isDay = self::isDaylight($timestamp);
        $tone = $isDay ? 'day' : 'night';

        return match (true) {
            $rain >= 60 => ['icon' => 'cloud-rain', 'label' => 'rain likely', 'tone' => 'rain'],
            $rain >= 30 => ['icon' => 'cloud-drizzle', 'label' => 'rain possible', 'tone' => 'rain'],
            $rain >= 10 => ['icon' => $isDay ? 'cloud-sun' : 'cloud-moon', 'label' => 'slight chance of rain', 'tone' => $tone],
            default => ['icon' => $isDay ? 'sun' : 'moon', 'label' => 'dry', 'tone' => $tone],
        };
    }

    /**
     * The photograph behind the sky, `{condition}-{day|night}` as named in
     * public/images/weather-backgrounds. Rain the microphone hears wins;
     * otherwise the next hour's rain chance on the thresholds the forecast
     * icons use. A clear picture only means a dry hour ahead and the overcast
     * pictures wait for a cloud reading. Null with neither: the plain sky
     * gradient stays rather than claim a weather it knows nothing about.
     *
     * @param  int|null  $rain  the next hour's rain chance in %
     */
    public static function scene(int $timestamp, ?bool $rainHeard, ?int $rain): ?string
    {
        $time = self::isDaylight($timestamp) ? 'day' : 'night';

        if ($rainHeard === true) {
            return "rain-{$time}";
        }

        return match (true) {
            $rain === null => null,
            $rain >= 60 => "rain-{$time}",
            $rain >= 30 => "drizzle-{$time}",
            $rain >= 10 => "partly-{$time}",
            default => "clear-{$time}",
        };
    }
}

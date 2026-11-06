<?php

declare(strict_types=1);

namespace App\ValueObject;

use Illuminate\Support\Facades\Date;

/** Mikulka + (measured − Mikulka) interpolated between a shaded anchor before sun-on and one after sun-off. */
final readonly class ShadeReference
{
    /** Calibrations of this feature version on are fitted and validated against it. */
    public const int SINCE_FEATURE_VERSION = 2;

    public const int BEFORE_SUN_SECONDS = 3600;

    public const int AFTER_SUN_ON_SECONDS = 1800;

    public const float SHADED_LUX = 300.0;

    public const int SETTLE_SECONDS = 1800;

    /** Also the end of the anchored window: shield heating history is not yet zero before it. */
    public const int AFTER_SUN_OFF_SECONDS = 3600;

    private const int ANCHOR_PAIRS = 2;

    /**
     * Pairs in [sun-on, sun-off + AFTER_SUN_OFF_SECONDS] are re-referenced, or dropped on a day without both anchors.
     *
     * @param  list<array{sample: ShieldSample, reference: float, reference_at: int}>  $pairs  Ordered by time.
     * @return list<array{sample: ShieldSample, reference: float, reference_at: int}>
     */
    public static function anchor(array $pairs): array
    {
        $days = [];

        foreach ($pairs as $pair) {
            $days[Date::createFromTimestamp($pair['sample']->timestamp, LocalTime::TIMEZONE)->format('Y-m-d')][] = $pair;
        }

        $anchored = [];

        foreach ($days as $day) {
            $window = self::window($day);
            $anchors = $window === null ? null : self::anchors($day, $window);

            foreach ($day as $pair) {
                $timestamp = $pair['sample']->timestamp;

                if ($window === null || $timestamp < $window['sun_on'] || $timestamp > $window['sun_off'] + self::AFTER_SUN_OFF_SECONDS) {
                    $anchored[] = $pair;

                    continue;
                }

                if ($anchors === null) {
                    continue;
                }

                $share = max(0.0, min(1.0, ($timestamp - $anchors['before_at']) / ($anchors['after_at'] - $anchors['before_at'])));
                $pair['reference'] += $anchors['before'] + $share * ($anchors['after'] - $anchors['before']);
                $anchored[] = $pair;
            }
        }

        return $anchored;
    }

    /**
     * @param  list<array{sample: ShieldSample, reference: float, reference_at: int}>  $day
     * @return array{sun_on: int, sun_off: int}|null
     */
    private static function window(array $day): ?array
    {
        $facing = array_values(array_filter($day, fn (array $pair): bool => $pair['sample']->facesSun));

        return $facing === [] ? null : ['sun_on' => $facing[0]['sample']->timestamp, 'sun_off' => $facing[array_key_last($facing)]['sample']->timestamp];
    }

    /**
     * @param  list<array{sample: ShieldSample, reference: float, reference_at: int}>  $day
     * @param  array{sun_on: int, sun_off: int}  $window
     * @return array{before_at: float, before: float, after_at: float, after: float}|null
     */
    private static function anchors(array $day, array $window): ?array
    {
        $before = array_values(array_filter($day, fn (array $pair): bool => $pair['sample']->timestamp >= $window['sun_on'] - self::BEFORE_SUN_SECONDS
            && $pair['sample']->timestamp < $window['sun_on'] + self::AFTER_SUN_ON_SECONDS && $pair['sample']->illuminance < self::SHADED_LUX));
        $after = array_values(array_filter($day, fn (array $pair): bool => $pair['sample']->timestamp >= $window['sun_off'] + self::SETTLE_SECONDS
            && $pair['sample']->timestamp <= $window['sun_off'] + self::AFTER_SUN_OFF_SECONDS));

        if (count($before) < self::ANCHOR_PAIRS || count($after) < self::ANCHOR_PAIRS) {
            return null;
        }

        return [
            'before_at' => self::mean(array_map(fn (array $pair): float => $pair['sample']->timestamp, $before)),
            'before' => self::mean(array_map(fn (array $pair): float => $pair['sample']->measured - $pair['reference'], $before)),
            'after_at' => self::mean(array_map(fn (array $pair): float => $pair['sample']->timestamp, $after)),
            'after' => self::mean(array_map(fn (array $pair): float => $pair['sample']->measured - $pair['reference'], $after)),
        ];
    }

    /** @param non-empty-list<float|int> $values */
    private static function mean(array $values): float
    {
        return array_sum($values) / count($values);
    }
}

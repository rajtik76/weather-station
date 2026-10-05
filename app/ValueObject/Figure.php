<?php

declare(strict_types=1);

namespace App\ValueObject;

/**
 * The page's number style: decimal comma, a plain space between thousands, the true minus (U+2212).
 */
final readonly class Figure
{
    private const string MINUS = '−';

    public static function format(float|int $value, int $decimals): string
    {
        $formatted = number_format($value, $decimals, ',', ' ');

        return str_starts_with($formatted, '-') ? self::MINUS.substr($formatted, 1) : $formatted;
    }

    /** Hundredths, the station's own resolution; `$signed` as in signed(). */
    public static function twoDecimals(float|int $value, bool $signed = false): string
    {
        return $signed ? self::signed($value, 2) : self::format($value, 2);
    }

    /** "to" once an end is below zero: a hyphen between signed figures reads as a third minus. */
    public static function range(float|int $low, float|int $high, int $decimals): string
    {
        $separator = $low < 0 || $high < 0 ? ' to ' : '-';

        return self::format($low, $decimals).$separator.self::format($high, $decimals);
    }

    /** The sign follows the value, not its rounding ("−0,0"); `$plusOnZero` off leaves zero bare. */
    public static function signed(float|int $value, int $decimals, bool $plusOnZero = true): string
    {
        $sign = match (true) {
            $value > 0 => '+',
            $value < 0 => self::MINUS,
            default => $plusOnZero ? '+' : '',
        };

        return $sign.self::format(abs($value), $decimals);
    }

    public static function withMinus(float|int $value, int $decimals = 0): string
    {
        return ($value < 0 ? self::MINUS : '').self::format(abs($value), $decimals);
    }
}

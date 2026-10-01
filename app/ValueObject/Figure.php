<?php

declare(strict_types=1);

namespace App\ValueObject;

/**
 * The page's number style in one place: decimal comma, a plain space between
 * thousands, and the true minus sign (U+2212) where a sign is printed.
 */
final readonly class Figure
{
    private const string MINUS = '−';

    /** "1 204,5", "−3,2": a negative value gets the true minus, as signed() prints it. */
    public static function format(float|int $value, int $decimals): string
    {
        $formatted = number_format($value, $decimals, ',', ' ');

        return str_starts_with($formatted, '-') ? self::MINUS.substr($formatted, 1) : $formatted;
    }

    /**
     * "13,5-15,1", or "−3,2 to −1,0" once an end is below zero: a hyphen
     * between two signed figures reads as a third minus.
     */
    public static function range(float|int $low, float|int $high, int $decimals): string
    {
        $separator = $low < 0 || $high < 0 ? ' to ' : '-';

        return self::format($low, $decimals).$separator.self::format($high, $decimals);
    }

    /**
     * "+0,4", "−1,2". The sign follows the value, not its rounding, so a
     * small negative prints as "−0,0". Zero is "+0" unless `$plusOnZero` is
     * off, which leaves it bare for a figure where zero means no change.
     */
    public static function signed(float|int $value, int $decimals, bool $plusOnZero = true): string
    {
        $sign = match (true) {
            $value > 0 => '+',
            $value < 0 => self::MINUS,
            default => $plusOnZero ? '+' : '',
        };

        return $sign.self::format(abs($value), $decimals);
    }

    /** "−60", "5": only a negative value gets a sign, the true minus. */
    public static function withMinus(float|int $value, int $decimals = 0): string
    {
        return ($value < 0 ? self::MINUS : '').self::format(abs($value), $decimals);
    }
}

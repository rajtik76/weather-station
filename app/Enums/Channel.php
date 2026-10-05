<?php

declare(strict_types=1);

namespace App\Enums;

use App\ValueObject\Figure;

/**
 * Value: key in DayFigures::metrics(). Tailwind classes are spelled out whole for the source scanner.
 */
enum Channel: string
{
    case Temperature = 't';
    case Humidity = 'h';
    case Pressure = 'p';
    case Noise = 'n';
    case Light = 'l';

    public function label(): string
    {
        return match ($this) {
            self::Temperature => 'Temperature',
            self::Humidity => 'Humidity',
            self::Pressure => 'Pressure, MSL',
            self::Noise => 'Noise, LAeq',
            self::Light => 'Light',
        };
    }

    public function code(): string
    {
        return match ($this) {
            self::Temperature => 'CH1',
            self::Humidity => 'CH2',
            self::Pressure => 'CH3',
            self::Noise => 'CH4',
            self::Light => 'AUX',
        };
    }

    /** CSS token name without `--`. */
    public function colour(): string
    {
        return match ($this) {
            self::Temperature => 'ch1',
            self::Humidity => 'ch2',
            self::Pressure => 'ch3',
            self::Noise => 'ch4',
            self::Light => 'aux',
        };
    }

    public function cssColour(): string
    {
        return "var(--{$this->colour()})";
    }

    public function backgroundClass(): string
    {
        return match ($this) {
            self::Temperature => 'bg-ch1',
            self::Humidity => 'bg-ch2',
            self::Pressure => 'bg-ch3',
            self::Noise => 'bg-ch4',
            self::Light => 'bg-aux',
        };
    }

    public function bandBackgroundClass(): string
    {
        return match ($this) {
            self::Temperature => 'bg-ch1/20',
            self::Humidity => 'bg-ch2/20',
            self::Pressure => 'bg-ch3/20',
            self::Noise => 'bg-ch4/20',
            self::Light => 'bg-aux/20',
        };
    }

    public function dimBackgroundClass(): string
    {
        return match ($this) {
            self::Temperature => 'bg-ch1/60',
            self::Humidity => 'bg-ch2/60',
            self::Pressure => 'bg-ch3/60',
            self::Noise => 'bg-ch4/60',
            self::Light => 'bg-aux/60',
        };
    }

    public function bandFillClass(): string
    {
        return match ($this) {
            self::Temperature => 'fill-ch1/18',
            self::Humidity => 'fill-ch2/18',
            self::Pressure => 'fill-ch3/18',
            self::Noise => 'fill-ch4/18',
            self::Light => 'fill-aux/18',
        };
    }

    public function textClass(): string
    {
        return match ($this) {
            self::Temperature => 'text-ch1',
            self::Humidity => 'text-ch2',
            self::Pressure => 'text-ch3',
            self::Noise => 'text-ch4',
            self::Light => 'text-aux',
        };
    }

    public function strokeClass(): string
    {
        return match ($this) {
            self::Temperature => 'stroke-ch1',
            self::Humidity => 'stroke-ch2',
            self::Pressure => 'stroke-ch3',
            self::Noise => 'stroke-ch4',
            self::Light => 'stroke-aux',
        };
    }

    public function unit(): string
    {
        return match ($this) {
            self::Temperature => '°C',
            self::Humidity => '%',
            self::Pressure => 'hPa',
            self::Noise => 'dB(A)',
            self::Light => 'lx',
        };
    }

    public function format(float $value, bool $signed = false): string
    {
        return match ($this) {
            self::Temperature, self::Humidity, self::Pressure => Figure::twoDecimals($value, $signed),
            self::Noise => $signed ? Figure::signed($value, 1) : Figure::format($value, 1),
            self::Light => $signed ? Figure::signed($value, 0) : Figure::format($value, 0),
        };
    }

    public function isLogarithmic(): bool
    {
        return $this === self::Light;
    }
}

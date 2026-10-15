<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Sensor;
use App\Queries\CorrectedTemperature;
use App\ValueObject\LocalTime;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('temperature:show {sensor}')]
#[Description('Show the newest measured temperature and calibrated shade estimate')]
class ShowCorrectedTemperature extends Command
{
    public function handle(CorrectedTemperature $temperatures): int
    {
        $sensor = Sensor::query()->where('slug', $this->argument('sensor'))->firstOrFail();
        $timestamp = $sensor->measurements()->max('timestamp');
        $result = $timestamp === null ? null : $temperatures->at($sensor->id, (int) $timestamp);

        if ($result === null || $result['estimated'] === null || $result['correction'] === null) {
            $this->warn('Shade estimate unavailable: no accepted calibration, insufficient light history or inputs outside calibration.');

            return self::FAILURE;
        }

        $this->table(['Time', 'Measured °C', 'Shade estimate °C', 'Shield heating °C', 'Calibration'], [[
            LocalTime::of($result['timestamp'])->stamp(),
            number_format($result['measured'], 2, '.', ''),
            number_format($result['estimated'], 2, '.', ''),
            number_format($result['correction'], 2, '.', ''),
            $result['calibration_id'],
        ]]);

        return self::SUCCESS;
    }
}

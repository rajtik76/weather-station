<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Queries\ShieldCalibrations;
use App\Queries\TemperatureCalibrator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Throwable;

#[Signature('temperature:validate')]
#[Description('Accept or reject every retrained calibration whose validation days are over')]
class ValidateTemperature extends Command
{
    public function handle(TemperatureCalibrator $calibrator, ShieldCalibrations $calibrations): int
    {
        foreach ($calibrations->validationEndedBy(Date::now()->getTimestamp()) as $calibration) {
            try {
                $calibrator->validate($calibration);
            } catch (Throwable $exception) {
                report($exception);
                $this->warn('Calibration #'.$calibration->id.' not validated: '.$exception->getMessage());

                continue;
            }

            $this->line('Sensor '.$calibration->sensor_id.': calibration #'.$calibration->id.' '.$calibration->status().'.');
        }

        return self::SUCCESS;
    }
}

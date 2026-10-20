<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TemperatureCalibration;
use App\Queries\ShieldCalibrations;
use App\Queries\TemperatureCalibrator;
use App\ValueObject\LocalTime;
use App\ValueObject\ShieldFeatures;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;

#[Signature('temperature:retrain')]
#[Description('Fit every calibrated sensor on the days before local midnight and validate it on the days after')]
class RetrainTemperature extends Command
{
    public const int TRAINING_DAYS = 14;

    public function handle(TemperatureCalibrator $calibrator, ShieldCalibrations $calibrations): int
    {
        $until = Date::now(LocalTime::TIMEZONE)->toImmutable()->startOfDay();
        $from = $until->subDays(self::TRAINING_DAYS);
        $validatedUntil = $until->addDays(TemperatureCalibrator::VALIDATION_DAYS);
        $sensorIds = TemperatureCalibration::query()->where('feature_version', ShieldFeatures::VERSION)
            ->distinct()->orderBy('sensor_id')->pluck('sensor_id');

        foreach ($sensorIds as $sensorId) {
            $newest = $calibrations->newest($sensorId);

            if (! $newest instanceof TemperatureCalibration || $newest->isValidating() || $newest->trained_until >= $until->getTimestamp()) {
                continue;
            }

            try {
                $calibration = $calibrator->train($sensorId, $from->getTimestamp(), $until->getTimestamp(), $validatedUntil->getTimestamp(), $newest->features());
            } catch (InvalidArgumentException $exception) {
                $this->warn('Sensor '.$sensorId.' not retrained: '.$exception->getMessage());

                continue;
            }

            $this->line('Sensor '.$sensorId.': calibration #'.$calibration->id.' validating until '.LocalTime::of($calibration->validated_until)->stamp().'.');
        }

        return self::SUCCESS;
    }
}

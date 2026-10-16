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
#[Description('Refit every calibrated sensor on a rolling window ending at local midnight')]
class RetrainTemperature extends Command
{
    public const int TRAINING_DAYS = 14;

    public const int VALIDATION_DAYS = 3;

    public function handle(TemperatureCalibrator $calibrator, ShieldCalibrations $calibrations): int
    {
        $until = Date::now(LocalTime::TIMEZONE)->toImmutable()->startOfDay();
        $validateFrom = $until->subDays(self::VALIDATION_DAYS);
        $from = $validateFrom->subDays(self::TRAINING_DAYS);
        $sensorIds = TemperatureCalibration::query()->where('feature_version', ShieldFeatures::VERSION)
            ->distinct()->orderBy('sensor_id')->pluck('sensor_id');

        foreach ($sensorIds as $sensorId) {
            $newest = $calibrations->newest($sensorId);

            if (! $newest instanceof TemperatureCalibration || $newest->validated_until >= $until->getTimestamp()) {
                continue;
            }

            try {
                $calibration = $calibrator->calibrate($sensorId, $from->getTimestamp(), $validateFrom->getTimestamp(), $until->getTimestamp(), $newest->features());
            } catch (InvalidArgumentException $exception) {
                $this->warn('Sensor '.$sensorId.' not retrained: '.$exception->getMessage());

                continue;
            }

            $this->line('Sensor '.$sensorId.': calibration #'.$calibration->id.($calibration->accepted ? ' accepted.' : ' rejected.'));
        }

        return self::SUCCESS;
    }
}

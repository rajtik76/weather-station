<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Sensor;
use App\Queries\TemperatureCalibrator;
use App\ValueObject\LocalTime;
use App\ValueObject\ShieldFeatures;
use App\ValueObject\StationSite;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

#[Signature('temperature:calibrate {sensor} {--from=} {--validate-from=} {--until=} {--latitude=} {--longitude=} {--wall-azimuth=}')]
#[Description('Fit and validate a sensor-specific radiation shield heating correction')]
class CalibrateTemperature extends Command
{
    public function handle(TemperatureCalibrator $calibrator): int
    {
        $from = $this->option('from');
        $validateFrom = $this->option('validate-from');

        if ($from === null || $validateFrom === null) {
            $this->error('Both --from and --validate-from are required.');

            return self::FAILURE;
        }

        $today = Date::now(LocalTime::TIMEZONE)->format('Y-m-d');
        $options = [
            ...$this->options(),
            'until' => $this->option('until') ?? $today,
            'latitude' => $this->option('latitude') ?? StationSite::LATITUDE,
            'longitude' => $this->option('longitude') ?? StationSite::LONGITUDE,
            'wall-azimuth' => $this->option('wall-azimuth') ?? StationSite::WALL_AZIMUTH,
        ];
        Validator::make($options, [
            'from' => ['required', 'date_format:Y-m-d'],
            'validate-from' => ['required', 'date_format:Y-m-d', 'after:from'],
            'until' => ['required', 'date_format:Y-m-d', 'after:validate-from', 'before_or_equal:'.$today],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'wall-azimuth' => ['required', 'numeric', 'gte:0', 'lt:360'],
        ])->validate();
        $sensor = Sensor::query()->where('slug', $this->argument('sensor'))->firstOrFail();
        $epoch = fn (string $date): int => Date::parse($date, LocalTime::TIMEZONE)->getTimestamp();

        try {
            $calibration = $calibrator->calibrate(
                $sensor->id,
                $epoch($from),
                $epoch($validateFrom),
                $epoch($options['until']),
                new ShieldFeatures((float) $options['latitude'], (float) $options['longitude'], (float) $options['wall-azimuth']),
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $rows = [];

        foreach ($calibration->scores['validation'] as $group => $score) {
            $rows[] = [$group, $score['count'], $score['unavailable'],
                $this->decimal($score['raw_mae']), $this->decimal($score['corrected_mae']),
                $this->decimal($score['raw_bias']), $this->decimal($score['corrected_bias'])];
        }

        $this->table(['Validation', 'Pairs', 'Unavailable', 'Raw MAE °C', 'Corrected MAE °C', 'Raw bias °C', 'Corrected bias °C'], $rows);

        foreach ($calibration->scores['days'] as $day => $score) {
            $this->line($day.': '.$score['count'].' pairs, '.$this->decimal($score['raw_mae']).' -> '.$this->decimal($score['corrected_mae']).' °C MAE');
        }

        foreach ($calibration->scores['versus_current']['current'] ?? [] as $group => $score) {
            $this->line('Versus model in use, '.$group.': '.$this->decimal($score['corrected_mae']).' -> '.$this->decimal($calibration->scores['versus_current']['retrained'][$group]['corrected_mae'] ?? null).' °C MAE on '.$score['count'].' pairs');
        }

        $this->line('Calibration #'.$calibration->id.($calibration->accepted ? ' accepted.' : ' rejected; inference will not use it.'));

        return $calibration->accepted ? self::SUCCESS : self::FAILURE;
    }

    private function decimal(?float $value): string
    {
        return $value === null ? 'n/a' : number_format($value, 3, '.', '');
    }
}

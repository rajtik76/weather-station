<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\RainReportSource;
use App\Models\RainReport;
use App\Models\Sensor;
use App\Queries\Discord;
use App\Queries\RainHearing;
use App\Queries\Stations;
use App\ValueObject\LocalTime;
use App\ValueObject\RainPrompt;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

#[Signature('rain:watch')]
#[Description('Ask on Discord whether it rains whenever the rain detector changes its mind')]
class WatchRain extends Command
{
    public function handle(Stations $stations, RainHearing $hearing, Discord $discord): int
    {
        if (! $discord->isConfigured()) {
            $this->line('Discord is not configured.');

            return self::SUCCESS;
        }

        $failed = false;

        foreach ($stations->all() as $sensor) {
            try {
                $this->recordChange($sensor, $hearing);
                $this->postUnsent($sensor, $discord);
            } catch (Throwable $exception) {
                $failed = true;
                $this->error($sensor->name.': '.$exception->getMessage());
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function recordChange(Sensor $sensor, RainHearing $hearing): void
    {
        $window = $hearing->newest($sensor->id, now()->getTimestamp());

        if ($window === null) {
            return;
        }

        $last = $this->detectorReports($sensor)->orderByDesc('timestamp')->first();

        if (($last->detected ?? false) === $window['heard']) {
            return;
        }

        if (! $window['heard'] && ! $hearing->dryThrough($sensor->id, $window['timestamp'])) {
            return;
        }

        RainReport::query()->create([
            'sensor_id' => $sensor->id,
            'source' => RainReportSource::Detector,
            'timestamp' => $window['timestamp'],
            'detected' => $window['heard'],
        ]);
    }

    /** Retried every run until Discord accepts the message. */
    private function postUnsent(Sensor $sensor, Discord $discord): void
    {
        $unsent = $this->detectorReports($sensor)->whereNull('discord_message_id')->orderBy('timestamp')->get();

        foreach ($unsent as $report) {
            $report->update(['discord_message_id' => $discord->post(new RainPrompt($report)->message())]);

            $this->info($sensor->name.': '.($report->detected === true ? 'rain heard' : 'rain no longer heard').' at '.LocalTime::of($report->timestamp)->stamp().', asked on Discord.');
        }
    }

    /**
     * @return Builder<RainReport>
     */
    private function detectorReports(Sensor $sensor): Builder
    {
        return RainReport::query()->where('sensor_id', $sensor->id)->where('source', RainReportSource::Detector);
    }
}

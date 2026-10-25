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
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Throwable;

#[Signature('rain:watch')]
#[Description('Ask on Discord whether it rains whenever the rain detector changes its mind')]
class WatchRain extends Command
{
    private const int QUIET_FROM_HOUR = 22;

    private const int QUIET_UNTIL_HOUR = 7;

    private const int PROMPT_GAP_SECONDS = 30 * 60;

    private const string LAST_PROMPT_KEY = 'rain:watch:last-prompt';

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
                $this->postNewest($sensor, $discord);
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

    /** Newest unsent report only; retried until Discord accepts it. */
    private function postNewest(Sensor $sensor, Discord $discord): void
    {
        $promptHoursStart = $this->promptHoursStart();

        if ($promptHoursStart === null || Cache::has(self::LAST_PROMPT_KEY)) {
            return;
        }

        $report = $this->detectorReports($sensor)
            ->whereNull('discord_message_id')
            ->where('timestamp', '>=', $promptHoursStart)
            ->orderByDesc('timestamp')
            ->first();

        if (! $report instanceof RainReport) {
            return;
        }

        $report->update(['discord_message_id' => $discord->post(new RainPrompt($report)->message())]);
        Cache::put(self::LAST_PROMPT_KEY, true, self::PROMPT_GAP_SECONDS);

        $this->info($sensor->name.': '.($report->detected === true ? 'rain heard' : 'rain no longer heard').' at '.LocalTime::of($report->timestamp)->stamp().', asked on Discord.');
    }

    /** Today's QUIET_UNTIL_HOUR as a timestamp, null during quiet hours. */
    private function promptHoursStart(): ?int
    {
        $now = CarbonImmutable::now(LocalTime::TIMEZONE);

        if ($now->hour < self::QUIET_UNTIL_HOUR || $now->hour >= self::QUIET_FROM_HOUR) {
            return null;
        }

        return $now->setTime(self::QUIET_UNTIL_HOUR, 0)->getTimestamp();
    }

    /**
     * @return Builder<RainReport>
     */
    private function detectorReports(Sensor $sensor): Builder
    {
        return RainReport::query()->where('sensor_id', $sensor->id)->where('source', RainReportSource::Detector);
    }
}

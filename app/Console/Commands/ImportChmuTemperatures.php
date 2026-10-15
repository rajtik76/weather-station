<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Queries\ChmuReference;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Validator;
use Throwable;

#[Signature('temperature:import-reference {files?*} {--date=} {--month=}')]
#[Description('Import ČHMÚ Mikulka air temperatures from local JSON files or open data')]
class ImportChmuTemperatures extends Command
{
    public function handle(ChmuReference $reference): int
    {
        Validator::make($this->options(), [
            'date' => ['nullable', 'date_format:Y-m-d', 'prohibits:month'],
            'month' => ['nullable', 'date_format:Y-m'],
        ])->validate();

        $count = 0;

        try {
            $files = $this->argument('files');

            if ($files !== []) {
                foreach ($files as $file) {
                    $body = file_get_contents($file);

                    if ($body === false) {
                        $this->error('Cannot read '.$file);

                        return self::FAILURE;
                    }

                    $count += $reference->import($body, basename($file));
                }
            } elseif ($this->option('month') !== null) {
                $count = $reference->download($this->option('month'), monthly: true);
            } elseif ($this->option('date') !== null) {
                $count = $reference->download($this->option('date'));
            } else {
                $failed = false;

                foreach ([Date::now('UTC')->subDay(), Date::now('UTC')] as $date) {
                    try {
                        $count += $reference->download($date->format('Y-m-d'));
                    } catch (Throwable $exception) {
                        $failed = true;
                        $this->error($date->format('Y-m-d').': '.$exception->getMessage());
                    }
                }

                if ($failed) {
                    $this->warn($count.' reference observations imported; one or more days unavailable.');

                    return self::FAILURE;
                }
            }
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($count.' reference observations imported (ČHMÚ Plzeň-Mikulka, CC BY 4.0).');

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Queries\ChmuReference;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Validator;
use Throwable;

#[Signature('temperature:import-reference {files?*} {--date=} {--month=} {--days=}')]
#[Description('Import ČHMÚ Mikulka air temperatures from local JSON files or open data')]
class ImportChmuTemperatures extends Command
{
    public function handle(ChmuReference $reference): int
    {
        Validator::make($this->options(), [
            'date' => ['nullable', 'date_format:Y-m-d', 'prohibits:month,days'],
            'month' => ['nullable', 'date_format:Y-m', 'prohibits:days'],
            'days' => ['nullable', 'integer', 'between:1,30'],
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
                $today = Date::now('UTC')->format('Y-m-d');
                $dates = [Date::now('UTC')->subDay()->format('Y-m-d'), $today];

                if ($this->option('days') !== null) {
                    $dates = [];
                    $months = [];

                    for ($offset = (int) $this->option('days'); $offset >= 0; $offset--) {
                        $day = Date::now('UTC')->subDays($offset);

                        if ($day->format('Y-m') !== substr($today, 0, 7)) {
                            $months[$day->format('Y-m')][] = $day->format('Y-m-d');
                        } else {
                            $dates[] = $day->format('Y-m-d');
                        }
                    }

                    foreach ($months as $month => $monthDates) {
                        try {
                            $count += $reference->download($month, monthly: true);
                        } catch (RequestException $exception) {
                            if ($exception->response->status() !== 404) {
                                $failed = true;
                                $this->error($month.': '.$exception->getMessage());

                                continue;
                            }

                            $this->line($month.': monthly file not published yet, using daily files.');

                            foreach ($monthDates as $date) {
                                try {
                                    $count += $reference->download($date, archived: true);
                                } catch (Throwable $dailyException) {
                                    $failed = true;
                                    $this->error($date.': '.$dailyException->getMessage());
                                }
                            }
                        } catch (Throwable $exception) {
                            $failed = true;
                            $this->error($month.': '.$exception->getMessage());
                        }
                    }
                }

                foreach ($dates as $date) {
                    try {
                        $archived = $this->option('days') !== null && $date < Date::now('UTC')->subDays(2)->format('Y-m-d');
                        $count += $reference->download($date, archived: $archived);
                    } catch (RequestException $exception) {
                        if ($date === $today && $exception->response->status() === 404) {
                            $this->line($date.': not published yet.');

                            continue;
                        }

                        $failed = true;
                        $this->error($date.': '.$exception->getMessage());
                    } catch (Throwable $exception) {
                        $failed = true;
                        $this->error($date.': '.$exception->getMessage());
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

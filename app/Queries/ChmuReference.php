<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\ReferenceTemperature;
use App\ValueObject\ChmuTemperatures;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

final readonly class ChmuReference
{
    public const string BASE_URL = 'https://opendata.chmi.cz/meteorology/climate';

    public function download(string $date, bool $monthly = false): int
    {
        $stamp = str_replace('-', '', $date);
        $path = $monthly
            ? '/recent/data/10min/'.substr($stamp, 4, 2).'/10m-'.ChmuTemperatures::STATION.'-'.$stamp.'.json'
            : '/now/data/10m-'.ChmuTemperatures::STATION.'-'.$stamp.'.json';
        $url = self::BASE_URL.$path;
        $response = Http::connectTimeout(5)->timeout(30)->retry(2, 500, fn (Throwable $exception): bool => $exception instanceof ConnectionException
            || ($exception instanceof RequestException && ($exception->response->serverError() || $exception->response->status() === 429))
        )->get($url)->throw();

        return $this->import($response->body(), $url);
    }

    public function import(string $json, string $source): int
    {
        $rows = array_map(fn (array $row): array => [...$row, 'source' => $source], ChmuTemperatures::parse($json));

        DB::transaction(function () use ($rows): void {
            foreach (array_chunk($rows, 500) as $chunk) {
                ReferenceTemperature::query()->upsert($chunk, ['station', 'timestamp'], ['temperature', 'quality', 'source', 'updated_at']);
            }
        });

        return count($rows);
    }
}

<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Forecast;
use Illuminate\Support\Facades\Http;

/**
 * The forecast service (forecast/serve.py) over HTTP. Holds only the address:
 * what to send and what a failed call means is for the job that asks. Every
 * call throws on an error status; a refused connection throws too.
 *
 * @phpstan-import-type Horizon from Forecast
 * @phpstan-import-type Band from Forecast
 *
 * @phpstan-type Issued array{issued_at: int, model: string, corrected: bool, correction?: int, horizons: list<Horizon>}
 * @phpstan-type BaseHorizon array{hours: int, temperature: Band, humidity: Band, rain_probability?: float}
 * @phpstan-type BaseAnswer array{model: string, forecasts: list<array{issued_at: int, horizons: list<BaseHorizon>}>}
 */
final readonly class ForecastService
{
    public function __construct(private string $url) {}

    public static function fromConfig(): self
    {
        return new self((string) config('forecast.url'));
    }

    /**
     * The next hours from the readings sent, the station correction included.
     *
     * @param  array<string, mixed>  $payload
     * @return Issued
     */
    public function forecast(array $payload, int $timeout = 30): array
    {
        /** @var Issued */
        return Http::timeout($timeout)->post($this->endpoint('forecast'), $payload)->throw()->json();
    }

    /**
     * The base models alone, for every forecast the readings allow.
     *
     * @param  array<string, mixed>  $payload
     * @return BaseAnswer
     */
    public function base(array $payload, int $timeout = 120): array
    {
        /** @var BaseAnswer */
        return Http::timeout($timeout)->post($this->endpoint('base'), $payload)->throw()->json();
    }

    /**
     * @return array{model: string}
     */
    public function health(int $timeout = 10): array
    {
        /** @var array{model: string} */
        return Http::timeout($timeout)->get($this->endpoint('health'))->throw()->json();
    }

    private function endpoint(string $path): string
    {
        return rtrim($this->url, '/').'/'.$path;
    }
}

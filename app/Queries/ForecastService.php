<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Forecast;
use Illuminate\Support\Facades\Http;

/**
 * The forecast service (forecast/serve.py) over HTTP; every call throws on an error status or refused connection.
 *
 * @phpstan-import-type Horizon from Forecast
 * @phpstan-import-type Band from Forecast
 *
 * @phpstan-type Issued array{issued_at: int, model: string, corrected: bool, correction?: int, horizons: list<Horizon>}
 * @phpstan-type BaseHorizon array{hours: int, temperature: Band, humidity: Band, rain_probability?: float}
 * @phpstan-type BaseAnswer array{model: string, forecasts: list<array{issued_at: int, horizons: list<BaseHorizon>}>}
 * @phpstan-type Fitted array{model: string, correction: int, targets: array<string, array{intercept: float, coefficients: array<string, float>, widen: float}>}
 * @phpstan-type LightHorizon array{hours: int, baseline: float, trees: list<list<list<int|float|null>>>, widen: float}
 * @phpstan-type LightFitted array{model: string, version: string, fit: array{day: string, horizons: list<LightHorizon>}}
 * @phpstan-type LightIssued array{issued_at: int, model: string, version: string, horizons: list<array{hours: int, temperature: Band}>}
 */
final readonly class ForecastService
{
    public function __construct(private string $url) {}

    public static function fromConfig(): self
    {
        return new self((string) config('forecast.url'));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return Issued
     */
    public function forecast(array $payload, int $timeout = 30): array
    {
        /** @var Issued */
        return Http::timeout($timeout)->post($this->endpoint('forecast'), $payload)->throw()->json();
    }

    /**
     * Station correction fitted on the readings, to be sent back with each forecast.
     *
     * @param  array<string, mixed>  $payload
     * @return Fitted
     */
    public function correction(array $payload, int $timeout = 120): array
    {
        /** @var Fitted */
        return Http::timeout($timeout)->post($this->endpoint('correction'), $payload)->throw()->json();
    }

    /**
     * Base models alone, for every forecast the readings allow.
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
     * @return array{model: string, experiment?: string}
     */
    public function health(int $timeout = 10): array
    {
        /** @var array{model: string, experiment?: string} */
        return Http::timeout($timeout)->get($this->endpoint('health'))->throw()->json();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return LightFitted
     */
    public function lightCorrection(array $payload, int $timeout = 120): array
    {
        /** @var LightFitted */
        return Http::timeout($timeout)->post($this->endpoint('light-correction'), $payload)->throw()->json();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return LightIssued
     */
    public function lightForecast(array $payload, int $timeout = 30): array
    {
        /** @var LightIssued */
        return Http::timeout($timeout)->post($this->endpoint('light-forecast'), $payload)->throw()->json();
    }

    private function endpoint(string $path): string
    {
        return rtrim($this->url, '/').'/'.$path;
    }
}

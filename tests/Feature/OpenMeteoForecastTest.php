<?php

declare(strict_types=1);

use App\Queries\OpenMeteoForecast;
use App\ValueObject\StationSite;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('interpolates the hourly model temperature to the middle of each scored window', function (): void {
    $hour = 1_789_999_200;
    Http::fake(['https://nwp.test/*' => Http::response(['hourly' => [
        'time' => [$hour, $hour + 3600, $hour + 7200, $hour + 10800],
        'temperature_2m' => [10.0, 11.0, 12.5, null],
    ]])]);

    // Issued 20 min past the hour: the 1 h window's middle is 25 min past the next one.
    $temperatures = new OpenMeteoForecast('https://nwp.test/v1/forecast', 'icon_seamless')
        ->temperatures($hour + 1200, [1, 2, 6]);

    expect($temperatures)->toBe([1 => 11.63]);
    Http::assertSent(fn (Request $request): bool => $request['latitude'] === StationSite::LATITUDE
        && $request['longitude'] === StationSite::LONGITUDE
        && $request['elevation'] === StationSite::ALTITUDE_METRES
        && $request['models'] === 'icon_seamless'
        && $request['hourly'] === 'temperature_2m'
        && $request['forecast_hours'] === 8);
});

it('is off without a URL', function (): void {
    config()->set('forecast.nwp.url', '');

    expect(OpenMeteoForecast::fromConfig())->toBeNull();

    config()->set('forecast.nwp.url', 'https://nwp.test/v1/forecast');

    expect(OpenMeteoForecast::fromConfig())->toBeInstanceOf(OpenMeteoForecast::class);
});

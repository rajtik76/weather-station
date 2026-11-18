<?php

declare(strict_types=1);

use App\Enums\WindIntensity;
use App\ValueObject\WindDetector;

/**
 * Windows from production, 2026.
 *
 * @return list<int>
 */
function productionSpectrum(string $window): array
{
    return match ($window) {
        'gusts noted on 18.11. at 13:09' => [7852, 7465, 7135, 7089, 6767, 6464, 6298, 6146, 5934, 5834, 5678, 5460, 5440, 5350, 5218, 5288, 5266, 5318, 5300, 5056, 4909, 4630, 4465, 4496, 4514, 4119],
        'gusts noted on 18.10. at 15:59' => [8254, 7755, 7383, 7340, 6907, 6548, 6511, 6387, 6143, 6123, 5995, 5789, 5789, 5783, 5742, 5813, 5793, 5791, 5845, 5626, 5518, 5337, 5313, 5356, 5271, 5064],
        'a breeze on the night of 24.10.' => [7131, 6726, 6403, 6363, 5930, 5619, 5502, 5373, 5070, 4959, 4728, 4377, 4109, 3990, 3764, 3965, 4117, 4075, 4200, 3936, 3438, 2981, 2510, 2529, 2567, 2066],
        'morning traffic, flat to 31.5 Hz' => [7469, 7299, 6730, 6696, 6256, 5934, 5790, 5125, 4839, 4833, 4571, 4674, 4725, 4741, 4730, 4991, 5117, 5282, 5142, 4699, 4439, 4284, 4218, 4159, 4212, 3442],
        'a quiet night' => [5575, 5178, 5017, 5218, 4273, 3853, 3798, 3312, 3079, 3131, 3129, 3173, 3132, 3407, 2816, 3303, 3090, 3225, 3117, 2237, 2449, 2083, 1839, 1966, 1790, 1332],
        default => throw new InvalidArgumentException("No window {$window}."),
    };
}

it('hears wind in the rumble that falls steeply from 25 Hz', function (string $window, ?WindIntensity $wind): void {
    expect(WindDetector::hears(productionSpectrum($window)))->toBe($wind);
})->with([
    'gusts noted on 18.11. at 13:09' => ['gusts noted on 18.11. at 13:09', WindIntensity::Strong],
    'gusts noted on 18.10. at 15:59' => ['gusts noted on 18.10. at 15:59', WindIntensity::Strong],
    'a breeze on the night of 24.10.' => ['a breeze on the night of 24.10.', WindIntensity::Breeze],
    'morning traffic, flat to 31.5 Hz' => ['morning traffic, flat to 31.5 Hz', null],
    'a quiet night' => ['a quiet night', null],
]);

it('hears nothing without the low bands', function (): void {
    expect(WindDetector::hears([]))->toBeNull();
});

it('draws the line on every threshold', function (int $rumble, int $next, int $traffic, ?WindIntensity $wind): void {
    $bands = array_fill(0, 26, 3000);
    $bands[0] = $rumble;
    $bands[1] = $next;
    $bands[8] = $traffic;

    expect(WindDetector::hears($bands))->toBe($wind);
})->with([
    'on the breeze thresholds' => [7000, 6750, 4700, WindIntensity::Breeze],
    'rumble just under' => [6999, 6749, 4699, null],
    'first step too shallow' => [7000, 6751, 4700, null],
    'falls too far to 160 Hz' => [7000, 6750, 4699, null],
    'on the strong threshold' => [7500, 7250, 5200, WindIntensity::Strong],
]);

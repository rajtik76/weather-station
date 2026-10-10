<?php

declare(strict_types=1);

use App\ValueObject\RaceEntrants;

/** @return array{low: float, mid: float, high: float} */
function entrantBand(float $mid): array
{
    return ['low' => $mid - 1, 'mid' => $mid, 'high' => $mid + 1];
}

it('reads the base band beside the race candidates and ignores anyone not racing', function (): void {
    $horizon = [
        ...forecastHorizon(1, 13.0, 70.0, 0.0),
        'base' => ['temperature' => entrantBand(12.0), 'humidity' => entrantBand(70.0)],
        'candidates' => ['correction' => entrantBand(13.0), 'light-v6' => entrantBand(12.5), 'light-v2' => entrantBand(11.0)],
    ];

    expect(RaceEntrants::bands($horizon))->toEqual(['correction' => entrantBand(13.0), 'light-v6' => entrantBand(12.5), 'base' => entrantBand(12.0)])
        ->and(RaceEntrants::areAllIn($horizon))->toBeFalse();
});

it('enters the shown band as the correction only while nothing has been picked over it', function (): void {
    $fresh = forecastHorizon(1, 13.0, 70.0, 0.0);
    $picked = [...forecastHorizon(2, 12.5, 70.0, 0.0), 'shown_by' => 'light-v6', 'candidates' => ['light-v6' => entrantBand(12.5)]];

    expect(RaceEntrants::withCorrection([$fresh, $picked]))->toEqual([
        [...$fresh, 'candidates' => ['correction' => $fresh['temperature']]],
        $picked,
    ]);
});

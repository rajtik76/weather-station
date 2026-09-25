<?php

declare(strict_types=1);

use App\Enums\AccuracyGrade;

it('grades an accuracy by the percentage the page prints', function (float $percent, AccuracyGrade $grade): void {
    expect(AccuracyGrade::of($percent))->toBe($grade);
})->with([
    'all right' => [100.0, AccuracyGrade::Good],
    'on the good threshold' => [80.0, AccuracyGrade::Good],
    'printed as 80 %' => [79.6, AccuracyGrade::Good],
    'printed as 79 %' => [79.4, AccuracyGrade::Fair],
    'on the fair threshold' => [50.0, AccuracyGrade::Fair],
    'just under it' => [49.0, AccuracyGrade::Poor],
    'all wrong' => [0.0, AccuracyGrade::Poor],
]);

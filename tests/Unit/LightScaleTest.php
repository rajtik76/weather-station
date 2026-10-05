<?php

declare(strict_types=1);

use App\ValueObject\LightScale;

it('starts on the log scale', function (): void {
    expect(LightScale::of(LightScale::DEFAULT)->name)->toBe('log');
});

it('takes either scale by name', function (string $name): void {
    $scale = LightScale::of($name);

    expect($scale->name)->toBe($name)
        ->and($scale->is($name))->toBeTrue();
})->with(['log', 'linear']);

it('falls back to the log scale for an unknown name', function (): void {
    $scale = LightScale::of('cubic');

    expect($scale->name)->toBe('log')
        ->and($scale->is('cubic'))->toBeFalse();
});

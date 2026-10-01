<?php

declare(strict_types=1);

use App\ValueObject\ChannelSelection;

it('starts with temperature and humidity on and the dew point off', function (): void {
    $channels = ChannelSelection::of([]);

    expect($channels->toArray())->toBe(['t' => true, 'h' => true, 'd' => false])
        ->and($channels->hidden())->toBe(['d']);
});

it('switches a channel on and off', function (): void {
    $channels = ChannelSelection::of(ChannelSelection::DEFAULTS)->toggle('d')->toggle('h');

    expect($channels->toArray())->toBe(['t' => true, 'h' => false, 'd' => true])
        ->and($channels->hidden())->toBe(['h']);
});

it('keeps the last channel on', function (): void {
    $channels = ChannelSelection::of(['t' => true, 'h' => false, 'd' => false]);

    expect($channels->isLast('t'))->toBeTrue()
        ->and($channels->toggle('t')->toArray())->toBe(['t' => true, 'h' => false, 'd' => false])
        ->and($channels->isLast('h'))->toBeFalse();
});

it('reads the state against the defaults, ignoring keys it does not know', function (): void {
    $channels = ChannelSelection::of(['h' => false, 'x' => true]);

    expect($channels->toArray())->toBe(['t' => true, 'h' => false, 'd' => false])
        ->and($channels->toggle('x')->toArray())->toBe($channels->toArray());
});

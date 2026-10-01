<?php

declare(strict_types=1);

use App\ValueObject\ModelName;

it('names a model by when it was trained, in local time', function (): void {
    expect(ModelName::of('2026-09-24T08:40:43.136429+00:00'))->toBe('24.9.2026 10:40');
});

it('prints a name that is no timestamp as it came', function (): void {
    expect(ModelName::of('v3.4.0'))->toBe('v3.4.0');
});

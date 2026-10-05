<?php

declare(strict_types=1);

namespace App\ValueObject;

/**
 * Value axis of the light strip; an unknown name falls back to the default.
 */
final readonly class LightScale
{
    public const string LOG = 'log';

    public const string LINEAR = 'linear';

    public const string DEFAULT = self::LOG;

    /** @var array<string, string> */
    public const array LABELS = [self::LOG => 'Log', self::LINEAR => 'Linear'];

    private function __construct(public string $name) {}

    public static function of(string $name): self
    {
        return new self(array_key_exists($name, self::LABELS) ? $name : self::DEFAULT);
    }

    public function is(string $name): bool
    {
        return $this->name === $name;
    }
}

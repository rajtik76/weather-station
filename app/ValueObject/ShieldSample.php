<?php

declare(strict_types=1);

namespace App\ValueObject;

final readonly class ShieldSample
{
    /** @param list<float> $features */
    public function __construct(
        public int $timestamp,
        public float $measured,
        public float $illuminance,
        public SolarPosition $sun,
        public bool $facesSun,
        public array $features,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\ValueObject;

final readonly class ShieldModel
{
    /** @param list<float> $coefficients */
    public function __construct(public array $coefficients, public float $maxLux, public float $maxHeating) {}

    public function heating(ShieldSample $sample): ?float
    {
        if (count($sample->features) !== count($this->coefficients) || ($sample->facesSun && $sample->illuminance > $this->maxLux * 1.2)) {
            return null;
        }

        $heating = 0.0;

        foreach ($this->coefficients as $index => $coefficient) {
            $heating += $coefficient * $sample->features[$index];
        }

        return is_finite($heating) && $heating <= $this->maxHeating * 1.2 ? max(0.0, $heating) : null;
    }

    public function temperature(ShieldSample $sample): ?float
    {
        $heating = $this->heating($sample);

        return $heating === null ? null : $sample->measured - $heating;
    }
}

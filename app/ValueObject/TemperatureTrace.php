<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Queries\TemperatureHistory;

/** @phpstan-import-type TemperatureRow from TemperatureHistory */
final readonly class TemperatureTrace
{
    /** @param list<TemperatureRow> $readings */
    public function __construct(private array $readings) {}

    public function low(float $measuredLow): float
    {
        return min([$measuredLow, ...$this->estimates()]);
    }

    /** @return list<float> */
    private function estimates(): array
    {
        return array_values(array_filter(array_column($this->readings, 'estimated'), fn (?float $value): bool => $value !== null));
    }

    public function line(float $low, float $high): string
    {
        $path = '';
        $connected = false;
        $previous = null;
        $last = max(1, count($this->readings) - 1);

        foreach ($this->readings as $index => $reading) {
            if ($reading['estimated'] === null) {
                $connected = false;
                $previous = null;

                continue;
            }

            $join = $connected && $previous !== null && $reading['timestamp'] - $previous <= 900;
            $path .= ($join ? 'L' : 'M').round($index / $last * 100, 2).','.Trace::levelOf($reading['estimated'], $low, $high);
            $previous = $reading['timestamp'];
            $connected = true;
        }

        return $path;
    }

    public function latest(): ?float
    {
        return $this->readings === [] ? null : $this->readings[array_key_last($this->readings)]['estimated'];
    }
}

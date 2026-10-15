<?php

declare(strict_types=1);

namespace App\ValueObject;

use InvalidArgumentException;

final readonly class NonNegativeRegression
{
    /**
     * @param  list<list<float>>  $features
     * @param  list<float>  $targets
     * @return list<float>
     */
    public static function fit(array $features, array $targets): array
    {
        if ($features === [] || $features[0] === [] || count($features) !== count($targets)) {
            throw new InvalidArgumentException('Regression requires matching non-empty features and targets.');
        }

        $columns = count($features[0]);

        foreach ($features as $row => $values) {
            if (count($values) !== $columns || ! is_finite($targets[$row])) {
                throw new InvalidArgumentException('Regression requires finite rectangular data.');
            }

            foreach ($values as $value) {
                if (! is_finite($value) || $value < 0) {
                    throw new InvalidArgumentException('Heating features must be finite and non-negative.');
                }
            }
        }

        $coefficients = array_fill(0, $columns, 0.0);
        $residuals = $targets;
        $penalty = count($features) * 0.001;

        for ($iteration = 0; $iteration < 1000; $iteration++) {
            $change = 0.0;

            for ($column = 0; $column < $columns; $column++) {
                $numerator = 0.0;
                $denominator = $penalty;

                foreach ($features as $row => $values) {
                    $value = $values[$column];
                    $numerator += $value * ($residuals[$row] + $value * $coefficients[$column]);
                    $denominator += $value * $value;
                }

                $updated = max(0.0, $numerator / $denominator);
                $delta = $updated - $coefficients[$column];
                $change = max($change, abs($delta));
                $coefficients[$column] = $updated;

                foreach ($features as $row => $values) {
                    $residuals[$row] -= $values[$column] * $delta;
                }
            }

            if ($change < 0.000001) {
                break;
            }
        }

        return array_values($coefficients);
    }
}

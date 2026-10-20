<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Models\TemperatureCalibration;

/**
 * @phpstan-import-type Score from TemperatureScore
 *
 * @phpstan-type VersusCurrent array{current?: array<string, Score>, retrained?: array<string, Score>}
 * @phpstan-type HistoryRow array{id: int, status: string, trained_from: int, trained_until: int, validated_until: int, observations: ?int, previous_mae: ?float, own_mae: ?float, improvement: ?float, sun_improvement: ?float, in_use_from: ?int, in_use_until: ?int, in_use_now: bool}
 */
final readonly class CalibrationHistory
{
    /**
     * Improvement = previous model MAE − this model MAE on common validation pairs.
     *
     * @param  iterable<TemperatureCalibration>  $calibrations  Newest first.
     * @param  array<int, VersusCurrent>  $provisional  Scores so far, by id, for calibrations under validation.
     * @return list<HistoryRow>
     */
    public static function rows(iterable $calibrations, int $now, array $provisional = []): array
    {
        $spans = CalibrationSpans::of($calibrations);
        $rows = [];

        foreach ($calibrations as $calibration) {
            $span = $spans[$calibration->id] ?? null;
            $versus = $provisional[$calibration->id] ?? $calibration->scores['versus_current'] ?? [];
            $own = $versus['retrained']['all'] ?? $calibration->validationScores()['all'] ?? null;
            $previousMae = $versus['current']['all']['corrected_mae'] ?? null;
            $rows[] = [
                'id' => $calibration->id,
                'status' => $calibration->status(),
                'trained_from' => $calibration->trained_from,
                'trained_until' => $calibration->trained_until,
                'validated_until' => $calibration->validated_until,
                'observations' => $own['count'] ?? null,
                'previous_mae' => $previousMae,
                'own_mae' => $own['corrected_mae'] ?? null,
                'improvement' => self::improvement($previousMae, $own['corrected_mae'] ?? null),
                'sun_improvement' => self::improvement($versus['current']['sun_facing']['corrected_mae'] ?? null, $versus['retrained']['sun_facing']['corrected_mae'] ?? null),
                'in_use_from' => $span['from'] ?? null,
                'in_use_until' => $span['until'] ?? null,
                'in_use_now' => $span !== null && $span['from'] <= $now && ($span['until'] === null || $now < $span['until']),
            ];
        }

        return $rows;
    }

    private static function improvement(?float $current, ?float $retrained): ?float
    {
        return $current === null || $retrained === null ? null : $current - $retrained;
    }
}

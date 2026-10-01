<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Queries\ForecastAccuracy;

/**
 * The forecast page's verdict by horizon: skill, share in range and range width beside the base model's. Widths compare on hours that have a base, so the gap is the correction's.
 *
 * Bars are percentages of their track: skill from zero up (a loss draws none), share in range as is, so the 80 % target tick sits at 80 %.
 *
 * @phpstan-import-type Score from ForecastAccuracy
 *
 * @phpstan-type Row array{hours: int, count: int, skill: ?float, skillBar: float, inRange: float, inRangeBar: float, width: float, baseWidth: ?float, referenceSkill: ?float}
 */
final readonly class Scoreboard
{
    /**
     * @param  list<Score>  $scores
     * @param  array<int, ?float>  $reference  skill by hours ahead
     * @return list<Row>
     */
    public static function of(array $scores, array $reference = []): array
    {
        return array_map(function (array $score) use ($reference): array {
            $shown = $score['shown'];
            $base = $score['base'];

            return [
                'hours' => $score['hours'],
                'count' => $shown['count'],
                'skill' => $shown['skill'],
                'skillBar' => (float) max(0, min(100, $shown['skill'] ?? 0)),
                'inRange' => $shown['inRange'],
                'inRangeBar' => (float) max(0, min(100, $shown['inRange'])),
                'width' => $base === null ? $shown['width'] : $score['corrected']['width'],
                'baseWidth' => $base['width'] ?? null,
                'referenceSkill' => $reference[$score['hours']] ?? null,
            ];
        }, $scores);
    }
}

<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Measurement;
use App\ValueObject\ChartRow;
use Illuminate\Database\Eloquent\Builder;

/**
 * One sensor's whole record for the navigator, thinned to one real reading
 * per six hours once it is large enough to need it - it shows where the
 * window sits, and averaging would be work for nothing. The newest reading
 * always stays: the slider's axis ends on the last row, and without it the
 * right handle stops at the first reading of the newest bucket, up to six
 * hours short of now.
 *
 * @phpstan-import-type ReadingRow from ChartRow
 */
final readonly class RecordOverview
{
    private const int BUCKET_SECONDS = 21600;

    /** Below this the record is drawn whole: shorter than one bucket it would thin to a single point. */
    private const int UNTHINNED_ROWS = 1500;

    /** With no sensor the id is null and nothing matches. */
    public function __construct(private ?int $sensorId) {}

    /**
     * `$newest` is the newest reading's stamp the page already holds, kept
     * whatever bucket it falls in.
     *
     * @return list<ReadingRow>
     */
    public function rows(?int $newest): array
    {
        $total = $this->measurements()->count();

        return array_values(
            $this->measurements()
                ->when(
                    $total >= self::UNTHINNED_ROWS,
                    fn (Builder $query): Builder => $query->where(
                        fn (Builder $kept): Builder => new MeasurementBuckets($this->sensorId)->firstPerBucket($kept, self::BUCKET_SECONDS)
                            ->orWhere('timestamp', $newest)
                    )
                )
                ->orderBy('timestamp')
                ->get()
                ->map(fn (Measurement $measurement): array => ChartRow::reading($measurement))
                ->all()
        );
    }

    /**
     * @return Builder<Measurement>
     */
    private function measurements(): Builder
    {
        return Measurement::query()->where('sensor_id', $this->sensorId);
    }
}

<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Measurement;
use App\ValueObject\ChartRow;
use Illuminate\Database\Eloquent\Builder;

/**
 * The whole record for the navigator, thinned to one reading per six hours when large.
 * The newest reading always stays, or the slider's right handle stops up to six hours short.
 *
 * @phpstan-import-type ReadingRow from ChartRow
 */
final readonly class RecordOverview
{
    private const int BUCKET_SECONDS = 21600;

    /** Below this the record is drawn whole. */
    private const int UNTHINNED_ROWS = 1500;

    /** Null id matches nothing. */
    public function __construct(private ?int $sensorId) {}

    /**
     * `$newest` is the newest reading's stamp, kept whatever bucket it falls in.
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

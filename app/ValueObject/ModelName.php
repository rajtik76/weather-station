<?php

declare(strict_types=1);

namespace App\ValueObject;

/** A forecast model as the pages name it: by when it was trained, in local time. */
final readonly class ModelName
{
    /**
     * The service stamps a model with the ISO moment it was trained; anything
     * else prints as it came. Checked by shape first: strtotime() reads even
     * "v3.4.0" as a time.
     */
    public static function of(string $model): string
    {
        $trainedAt = preg_match('/^\d{4}-\d{2}-\d{2}T/', $model) === 1 ? strtotime($model) : false;

        return $trainedAt === false ? $model : LocalTime::of($trainedAt)->stamp();
    }
}

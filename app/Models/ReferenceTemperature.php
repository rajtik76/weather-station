<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ReferenceTemperatureFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReferenceTemperature extends Model
{
    /** @use HasFactory<ReferenceTemperatureFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['timestamp' => 'integer', 'temperature' => 'float', 'quality' => 'integer'];
    }
}

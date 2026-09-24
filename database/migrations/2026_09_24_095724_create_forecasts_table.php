<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forecasts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sensor_id')->constrained()->restrictOnDelete();
            // The reading's ten-minute window the forecast starts from, UTC Unix seconds.
            $table->unsignedInteger('issued_at');
            // trained_at of the model bundle that produced it.
            $table->string('model', 40);
            // Whether the station correction was applied.
            $table->boolean('corrected');
            // The service's "horizons" list as it arrived.
            $table->jsonb('data');
            $table->timestamps();

            $table->unique(['sensor_id', 'issued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecasts');
    }
};

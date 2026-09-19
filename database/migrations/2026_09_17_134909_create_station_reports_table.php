<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('station_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sensor_id')->constrained()->restrictOnDelete();
            // The `station` object as it arrived; the firmware decides what it reports.
            $table->jsonb('data');
            $table->timestamps();

            $table->index(['sensor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('station_reports');
    }
};

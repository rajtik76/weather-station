<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('forecasts');
    }

    public function down(): void
    {
        Schema::create('forecasts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sensor_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('issued_at');
            $table->string('model', 40);
            $table->boolean('corrected');
            $table->jsonb('data');
            $table->timestamps();
            $table->unsignedSmallInteger('correction')->nullable();
            $table->unique(['sensor_id', 'issued_at']);
        });
    }
};

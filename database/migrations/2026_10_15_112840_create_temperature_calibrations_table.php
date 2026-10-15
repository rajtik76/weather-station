<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temperature_calibrations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sensor_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('feature_version');
            $table->unsignedInteger('trained_from');
            $table->unsignedInteger('trained_until');
            $table->unsignedInteger('validated_until');
            $table->boolean('accepted')->default(false);
            $table->jsonb('geometry');
            $table->jsonb('coefficients');
            $table->jsonb('limits');
            $table->jsonb('scores');
            $table->timestamps();
            $table->index(['sensor_id', 'accepted', 'id']);
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('measurements', function (Blueprint $table): void {
            $table->id();
            $table->string('sensor_name', 50);
            $table->unsignedInteger('timestamp');
            $table->unsignedSmallInteger('protocol_version');
            $table->jsonb('data');
            $table->timestamps();

            $table->unique(['sensor_name', 'timestamp']);
            $table->index('timestamp');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('measurements');
    }
};

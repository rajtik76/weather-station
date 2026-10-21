<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rain_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sensor_id')->constrained()->restrictOnDelete();
            $table->string('source', 16);
            $table->unsignedInteger('timestamp');
            $table->boolean('detected')->nullable();
            $table->boolean('raining')->nullable();
            $table->string('discord_message_id', 32)->nullable();
            $table->timestamps();
            $table->index(['sensor_id', 'source', 'timestamp']);
        });
    }
};

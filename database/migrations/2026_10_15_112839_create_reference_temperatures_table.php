<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reference_temperatures', function (Blueprint $table): void {
            $table->id();
            $table->string('station', 32);
            $table->unsignedInteger('timestamp');
            $table->decimal('temperature', 6, 2)->nullable();
            $table->unsignedTinyInteger('quality');
            $table->string('source');
            $table->timestamps();
            $table->unique(['station', 'timestamp']);
        });
    }
};

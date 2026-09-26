<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forecasts', function (Blueprint $table): void {
            // CORRECTION_VERSION of the station correction the service ran; null when it sent none.
            $table->unsignedSmallInteger('correction')->nullable();
        });

        // Every forecast so far came from the first version, the two daily harmonics.
        DB::table('forecasts')->update(['correction' => 1]);
    }

    public function down(): void
    {
        Schema::table('forecasts', function (Blueprint $table): void {
            $table->dropColumn('correction');
        });
    }
};

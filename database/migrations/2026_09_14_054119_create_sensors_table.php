<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sensors', function (Blueprint $table): void {
            $table->id();
            // Same width as `sensor_name` in the payload.
            $table->string('name', 50)->unique();
            // See Sensor::uniqueSlug().
            $table->string('slug', 60)->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // Seed from the names already in the record.
        $names = DB::table('measurements')
            ->distinct()
            ->orderBy('sensor_name')
            ->pluck('sensor_name');

        $slugs = [];

        foreach ($names as $name) {
            $slug = $this->uniqueSlug($name, $slugs);
            $slugs[] = $slug;

            DB::table('sensors')->insert([
                'name' => $name,
                'slug' => $slug,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Same rule as Sensor::uniqueSlug(), copied so the migration does not
     * depend on a later model.
     *
     * @param  list<string>  $taken
     */
    private function uniqueSlug(string $name, array $taken): string
    {
        $base = Str::slug($name) ?: 'sensor';
        $slug = $base;

        for ($suffix = 2; in_array($slug, $taken, true); $suffix++) {
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }

    public function down(): void
    {
        Schema::dropIfExists('sensors');
    }
};

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
            // The identifier the firmware sends as `sensor_name`, so the same width.
            $table->string('name', 50)->unique();
            // The name as it can appear in a URL; see Sensor::uniqueSlug().
            $table->string('slug', 60)->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // Every sensor that ever uploaded is already named in the record, so
        // the table opens with exactly those rather than empty.
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
     * The same rule as Sensor::uniqueSlug(), kept here so the migration does
     * not depend on the model as it stands in some later commit.
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

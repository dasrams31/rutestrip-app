<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hiking_routes', function (Blueprint $table) {
            // Grade (%) of the steepest ~1 km stretch (python/processor.py
            // steepest_section_grade). Used for "landai"/"curam" ordering because
            // the average grade hides short steep parts. Filled by routes:reprocess.
            $table->float('steepest_grade_pct')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('hiking_routes', function (Blueprint $table) {
            $table->dropColumn('steepest_grade_pct');
        });
    }
};

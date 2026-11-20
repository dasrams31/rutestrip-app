<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hiking_routes', function (Blueprint $table) {
            $table->foreignId('uploaded_by')->nullable()->after('gpx_file_path')
                ->constrained('users')->nullOnDelete();
        });

        // Backfill from the uploader of each route's first GPX version.
        DB::statement('
            UPDATE hiking_routes SET uploaded_by = (
                SELECT gv.uploaded_by FROM gpx_versions gv
                WHERE gv.hiking_route_id = hiking_routes.id AND gv.uploaded_by IS NOT NULL
                ORDER BY gv.version_number ASC LIMIT 1
            )
        ');
    }

    public function down(): void
    {
        Schema::table('hiking_routes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('uploaded_by');
        });
    }
};

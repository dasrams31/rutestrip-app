<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hiking_routes', function (Blueprint $table) {
            // Trailhead location (OpenStreetMap Nominatim), used to rank searches
            // that name a place, e.g. "jalur landai di Jawa Barat".
            $table->string('province', 100)->nullable()->index();
            $table->string('regency', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('hiking_routes', function (Blueprint $table) {
            $table->dropIndex(['province']);
            $table->dropColumn(['province', 'regency']);
        });
    }
};

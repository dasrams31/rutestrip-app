<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * summit_route: true when the GPX runs from the trailhead to a summit, so the
 * Naismith time can be shown as "Waktu ke puncak". False for routes the
 * summit audit (python/output/route_audit/audit.csv) found are not summit
 * climbs, stop short of the summit, or are crossings and loops kept whole.
 */
return new class extends Migration
{
    private const NOT_TO_SUMMIT = [
        // not a summit climb (crater rim, viewpoint, forest trail)
        'Gunung Ijen', 'Gunung Tangkuban Parahu', 'Jalur Cisadon Sentul', 'Jalur Claket via Asitaba Pacet',
        'Kawah Ijen via Sunrise View Trek',
        // stop short of the summit, no trail data yet to complete them
        'Gunung Liman via Pulosari', 'Gunung Liman via Kare', 'Gunung Raung via Sumberwringin', 'Gunung Telomoyo via Arsal',
        // crossings and loops kept whole on purpose
        'Mt. Argopuro Baderan to Bremi', 'Gunung Liman via Wates Sedudo', 'Gunung Gede Pangrango via Tele Selatan - Sukatani',
        'Gunung Salak via Pasir Reugnit - Cimelati', 'Gunung Andong via Pendem (Loop)', 'Gunung Gegerbentang Loop',
    ];

    public function up(): void
    {
        Schema::table('hiking_routes', function (Blueprint $table) {
            $table->boolean('summit_route')->default(true);
        });

        DB::table('hiking_routes')->whereIn('name', self::NOT_TO_SUMMIT)->update(['summit_route' => false]);
    }

    public function down(): void
    {
        Schema::table('hiking_routes', function (Blueprint $table) {
            $table->dropColumn('summit_route');
        });
    }
};

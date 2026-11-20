<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Likes without login: a like (favorites row) belongs to a user or to an
 * anonymous visitor (rs_vid cookie). One like per route per user / per visitor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('favorites', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->string('visitor_id', 36)->nullable()->after('user_id');
            $table->unique(['hiking_route_id', 'visitor_id']);
        });
    }

    public function down(): void
    {
        \Illuminate\Support\Facades\DB::table('favorites')->whereNull('user_id')->delete();

        Schema::table('favorites', function (Blueprint $table) {
            $table->dropUnique(['hiking_route_id', 'visitor_id']);
            $table->dropColumn('visitor_id');
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};

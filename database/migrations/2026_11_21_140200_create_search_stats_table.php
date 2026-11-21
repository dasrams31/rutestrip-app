<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily count of guest searches per normalised phrase. search_histories only
 * keeps searches of logged-in users; this keeps guests in "most searched"
 * without storing anything about the guest (no IP, no cookie, no time of day).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_stats', function (Blueprint $table) {
            $table->id();
            $table->date('day');
            $table->string('query', 120);
            $table->unsignedInteger('hits')->default(0);

            $table->unique(['day', 'query']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_stats');
    }
};

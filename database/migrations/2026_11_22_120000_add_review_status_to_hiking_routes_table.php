<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hiking_routes', function (Blueprint $table) {
            // Recordings sent from the Android app wait for an admin ("pending") before
            // they show anywhere; everything else is published as before.
            $table->string('review_status', 16)->default('published')->index();
            $table->string('source', 16)->default('upload');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('hiking_routes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropIndex(['review_status']);
            $table->dropColumn(['review_status', 'source', 'reviewed_at', 'review_note']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Linimasa: one-way follows. Self-follows are refused in App\Services\SocialService. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('follower_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('followed_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['follower_id', 'followed_id']);
            $table->index(['followed_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follows');
    }
};

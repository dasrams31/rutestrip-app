<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Linimasa (social timeline, docs/API.md): status, photo, recording and story posts.
 * Plain text only. Stories expire 24 h after created_at (expires_at). likes_count and
 * comments_count are recounted inside the write transaction (App\Services\SocialService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 12);
            $table->text('body')->nullable();
            $table->string('photo_path')->nullable();
            $table->json('track')->nullable();
            $table->json('stats')->nullable();
            $table->json('place')->nullable();
            $table->foreignId('route_id')->nullable()->constrained('hiking_routes')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('likes_count')->default(0);
            $table->unsignedInteger('comments_count')->default(0);
            $table->timestamp('reported_at')->nullable();
            // App outbox id: a retry with the same id returns the post made the first time.
            $table->string('client_id', 40)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['user_id', 'client_id']);
            $table->index(['user_id', 'id']);
            $table->index(['type', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};

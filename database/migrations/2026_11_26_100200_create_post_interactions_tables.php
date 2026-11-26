<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Linimasa: likes, saves (private to the saver), comments and reports.
 * The auto ids on likes and saves only order lists (newest save first).
 * A report row per user and post or comment keeps it to one report each;
 * reported_at on the post or comment puts it on /admin/moderasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['post_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('post_saves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['post_id', 'user_id']);
            $table->index(['user_id', 'id']);
        });

        Schema::create('post_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamp('reported_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['post_id', 'id']);
        });

        Schema::create('post_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 300)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['post_id', 'user_id']);
        });

        Schema::create('post_comment_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_comment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 300)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['post_comment_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_comment_reports');
        Schema::dropIfExists('post_reports');
        Schema::dropIfExists('post_comments');
        Schema::dropIfExists('post_saves');
        Schema::dropIfExists('post_likes');
    }
};

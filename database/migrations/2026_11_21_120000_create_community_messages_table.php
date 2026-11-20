<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Obrolan Pendaki": community chat between logged-in hikers (left floating
 * button, CommunityChatController). Named community_messages because
 * chat_messages already holds the RAG chat history (ChatController).
 * Plain text only; soft deleted messages render as "Pesan dihapus".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('room', 40)->default('umum');
            $table->text('body');
            $table->timestamp('reported_at')->nullable();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->softDeletes();

            $table->index(['room', 'id']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_messages');
    }
};

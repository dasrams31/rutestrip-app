<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "Bagikan lokasi online" from the app: one secret link per share, only the
        // latest position is kept (no history), and it ends at expires_at or when stopped.
        Schema::create('live_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->string('label', 80)->nullable();
            $table->decimal('lat', 9, 6)->nullable();
            $table->decimal('lng', 9, 6)->nullable();
            $table->float('accuracy_m')->nullable();
            $table->float('elevation_m')->nullable();
            $table->unsignedTinyInteger('battery')->nullable();
            $table->timestamp('position_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('stopped_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'stopped_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_shares');
    }
};

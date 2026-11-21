<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When an account last signed in and how often (admin /admin/pengguna).
 * Written once per sign-in by ActivitySubscriber, never per request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_login_at')->nullable()->index();
            $table->unsignedInteger('login_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['last_login_at']);
            $table->dropColumn(['last_login_at', 'login_count']);
        });
    }
};

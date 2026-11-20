<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Unified accounts (docs/SSO.md): preferred language and the SSO token version.
     * Bumping sso_version invalidates every rs_sso token issued before.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 5)->default('id')->after('role');
            $table->unsignedInteger('sso_version')->default(0)->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['locale', 'sso_version']);
        });
    }
};

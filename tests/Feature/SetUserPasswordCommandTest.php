<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SetUserPasswordCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_sets_password_and_signs_out_other_sessions(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.test', 'role' => 'admin']);
        $before = (int) $user->sso_version;

        $this->artisan('user:set-password', ['email' => 'admin@example.test'])
            ->expectsQuestion('Password baru (min. 8 karakter, tidak tampil saat diketik)', 'test-pass-123')
            ->expectsQuestion('Ulangi password baru', 'test-pass-123')
            ->assertSuccessful();

        $user->refresh();
        $this->assertTrue(Hash::check('test-pass-123', $user->password));
        $this->assertSame($before + 1, (int) $user->sso_version);
    }

    public function test_rejects_mismatch_short_password_and_unknown_email(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.test']);
        $hash = $user->password;

        $this->artisan('user:set-password', ['email' => 'admin@example.test'])
            ->expectsQuestion('Password baru (min. 8 karakter, tidak tampil saat diketik)', 'test-pass-123')
            ->expectsQuestion('Ulangi password baru', 'something-else')
            ->assertFailed();

        $this->artisan('user:set-password', ['email' => 'admin@example.test'])
            ->expectsQuestion('Password baru (min. 8 karakter, tidak tampil saat diketik)', 'short')
            ->assertFailed();

        $this->artisan('user:set-password', ['email' => 'nobody@example.test'])->assertFailed();

        $this->assertSame($hash, $user->refresh()->password);
    }
}

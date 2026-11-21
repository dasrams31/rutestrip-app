<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Set a user's password from the server, e.g. when the admin forgot it.
 * The password is read from a hidden prompt (never a command argument, so it
 * doesn't end up in shell history), and other sessions are signed out.
 */
class SetUserPassword extends Command
{
    protected $signature = 'user:set-password {email : Email of the account}';

    protected $description = 'Set a new password for an account (hidden prompt)';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();
        if (! $user) {
            $this->error('Akun dengan email itu tidak ditemukan.');

            return self::FAILURE;
        }

        $this->line("Akun: {$user->name} <{$user->email}> ({$user->role})");

        $password = (string) $this->secret('Password baru (min. 8 karakter, tidak tampil saat diketik)');
        if (mb_strlen($password) < 8) {
            $this->error('Password minimal 8 karakter. Tidak ada yang diubah.');

            return self::FAILURE;
        }

        if ((string) $this->secret('Ulangi password baru') !== $password) {
            $this->error('Kedua password tidak sama. Tidak ada yang diubah.');

            return self::FAILURE;
        }

        $user->password = Hash::make($password);
        $user->sso_version = (int) $user->sso_version + 1; // sign out other sessions and the chat
        $user->save();

        $this->info('Password diganti. Silakan masuk lagi dengan password baru.');

        return self::SUCCESS;
    }
}

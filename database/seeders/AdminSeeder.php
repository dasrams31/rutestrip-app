<?php
namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminSeeder extends Seeder
{
    /**
     * Create or update the admin account.
     *
     * Credentials come from ADMIN_EMAIL / ADMIN_PASSWORD in .env. When ADMIN_PASSWORD
     * is empty a random password is generated and printed once.
     */
    public function run(): void
    {
        $email    = env('ADMIN_EMAIL', 'admin@rutestrip.ai.id');
        $password = env('ADMIN_PASSWORD') ?: Str::password(16);

        User::updateOrCreate(
            ['email' => $email],
            [
                'name'     => 'Admin RuteStrip',
                'email'    => $email,
                'password' => Hash::make($password),
                'role'     => 'admin',
            ]
        );

        $this->command->info('Admin user created/updated: ' . $email);

        if (! env('ADMIN_PASSWORD')) {
            $this->command->warn('Generated admin password (store it now): ' . $password);
        }
    }
}

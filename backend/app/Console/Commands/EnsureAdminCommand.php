<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class EnsureAdminCommand extends Command
{
    protected $signature = 'agrivision:admin
        {--email= : Admin email (default: admin@agrivision.local)}
        {--password= : Admin password (generated if omitted)}
        {--name= : Display name (default: Admin)}';

    protected $description = 'Create or reset the global AgriVision admin login.';

    public function handle(): int
    {
        $email = strtolower(trim((string) ($this->option('email') ?: 'admin@agrivision.local')));
        $name = trim((string) ($this->option('name') ?: 'Admin'));
        $password = (string) ($this->option('password') ?: '');
        $generated = false;

        if ($password === '') {
            $password = Str::password(16);
            $generated = true;
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Invalid email: '.$email);

            return self::FAILURE;
        }

        if (strlen($password) < 8) {
            $this->error('Password must be at least 8 characters.');

            return self::FAILURE;
        }

        // User model casts password to hashed — pass plaintext once.
        $user = User::query()->where('email', $email)->first();
        if ($user) {
            $user->forceFill([
                'name' => $name !== '' ? $name : $user->name,
                'password' => $password,
                'global_role' => 'admin',
            ])->save();
            $this->info("Updated admin: {$email}");
        } else {
            User::query()->create([
                'name' => $name !== '' ? $name : 'Admin',
                'email' => $email,
                'password' => $password,
                'global_role' => 'admin',
            ]);
            $this->info("Created admin: {$email}");
        }

        $this->line('Email:    '.$email);
        if ($generated) {
            $this->warn('Password: '.$password);
            $this->warn('Store this password now — it will not be shown again.');
        } else {
            $this->line('Password: (as provided)');
        }

        return self::SUCCESS;
    }
}

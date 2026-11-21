<?php

namespace App\Console\Commands;

use App\Models\LoginSecurity;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CreateCeoCommand extends Command
{
    protected $signature = 'app:create-ceo';

    protected $description = 'Create the initial CEO account interactively without hardcoded passwords.';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->ask('CEO email address')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Invalid email address.');

            return self::FAILURE;
        }

        if (User::query()->where('email', $email)->exists()) {
            $this->error('A user with this email already exists.');

            return self::FAILURE;
        }

        $name = trim((string) $this->ask('CEO full name (Arabic or primary)', 'المدير التنفيذي'));
        $nameEn = trim((string) $this->ask('CEO English name (optional)', 'Chief Executive Officer'));
        $title = trim((string) $this->ask('Job title', 'المدير التنفيذي'));

        $password = (string) $this->secret('Password (minimum 12 characters)');
        $confirm = (string) $this->secret('Confirm password');

        if ($password !== $confirm) {
            $this->error('Passwords do not match.');

            return self::FAILURE;
        }
        if (mb_strlen($password) < 12) {
            $this->error('Password must be at least 12 characters long.');

            return self::FAILURE;
        }

        $user = DB::transaction(function () use ($email, $name, $nameEn, $title, $password) {
            $u = User::create([
                'name' => $name,
                'name_en' => $nameEn !== '' ? $nameEn : null,
                'email' => $email,
                'role' => 'ceo',
                'title' => $title,
                'active' => true,
                'password' => $password,
            ]);

            LoginSecurity::create([
                'user_id' => $u->id,
                'failed_logins' => 0,
            ]);

            return $u;
        });

        $this->info("CEO account created successfully (ID: {$user->id}, Email: {$user->email}).");

        return self::SUCCESS;
    }
}

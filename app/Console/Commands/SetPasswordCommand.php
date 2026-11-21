<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class SetPasswordCommand extends Command
{
    protected $signature = 'app:set-password {email : User email address}';

    protected $description = 'Securely set or reset a user password interactively.';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            $this->error('User not found.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('New password (minimum 12 characters)');
        $confirm = (string) $this->secret('Confirm new password');

        if ($password !== $confirm) {
            $this->error('Passwords do not match.');

            return self::FAILURE;
        }
        if (mb_strlen($password) < 12) {
            $this->error('Password must be at least 12 characters long.');

            return self::FAILURE;
        }

        $user->password = $password;
        $user->save();
        $user->tokens()->delete();

        $this->info("Password updated and existing tokens revoked for {$user->email}.");

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class GrantAdmin extends Command
{
    protected $signature = 'user:admin {email : Email address of the account} {--revoke : Remove administrator access instead}';

    protected $description = 'Grant or revoke administrator access for a user';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->error("No user with email \"{$email}\".");

            return self::FAILURE;
        }

        if (! $this->option('revoke')) {
            $user->update(['is_admin' => true]);
            $this->info("{$user->email} is now an administrator.");

            return self::SUCCESS;
        }

        if (User::where('is_admin', true)->count() <= 1 && $user->is_admin) {
            $this->error('Refusing to remove the last administrator.');

            return self::FAILURE;
        }

        $user->update(['is_admin' => false]);
        $this->info("{$user->email} is no longer an administrator.");

        return self::SUCCESS;
    }
}

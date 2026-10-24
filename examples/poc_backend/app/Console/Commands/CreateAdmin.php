<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Creates the single administrator of this App (each App has its own database and admin).
 */
class CreateAdmin extends Command
{
    protected $signature = 'app:create-admin {email} {--name=Admin}';

    protected $description = 'Create the only Filament administrator for this App';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('Invalid email address.');

            return self::FAILURE;
        }

        if (User::query()->whereHas('roles', fn ($query) => $query->where('name', User::ADMIN_ROLE))->exists()) {
            $this->error('An administrator already exists for this App.');

            return self::FAILURE;
        }

        if (User::query()->where('email', $email)->exists()) {
            $this->error('This email is already used by a member account.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('Password (at least 12 characters)');
        $confirmation = (string) $this->secret('Repeat password');

        if (mb_strlen($password) < 12 || $password !== $confirmation) {
            $this->error('Password must be at least 12 characters and both entries must match.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($email, $password): void {
            $role = Role::findOrCreate(User::ADMIN_ROLE, 'web');

            $user = User::query()->create([
                'name' => (string) $this->option('name'),
                'display_name' => (string) $this->option('name'),
                'email' => $email,
                'password' => $password,
                'is_active' => true,
            ]);
            $user->markEmailAsVerified();
            $user->assignRole($role);
        });

        $this->info("Administrator {$email} created.");

        return self::SUCCESS;
    }
}

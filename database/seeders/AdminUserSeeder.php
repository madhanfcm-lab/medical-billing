<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Creates the first admin. Credentials come from ADMIN_EMAIL / ADMIN_PASSWORD (via config/clinic.php).
 * With no ADMIN_PASSWORD: local and testing get a well-known demo password; production gets a random one,
 * printed once, so a known default never reaches a live system.
 */
class AdminUserSeeder extends Seeder
{
    public const DEMO_PASSWORD = 'Admin@12345';

    public function run(): void
    {
        $email = config('clinic.admin.email');
        $existing = User::withTrashed()->where('email', $email)->first();

        if ($existing) {
            $existing->syncRoles([Role::Admin->value]);   // never touches an existing password

            return;
        }

        $password = config('clinic.admin.password') ?: (app()->isProduction() ? Str::password(16, symbols: false) : self::DEMO_PASSWORD);

        $admin = User::create([
            'name' => config('clinic.admin.name'),
            'email' => $email,
            'password' => $password,
            'is_active' => true,
        ]);
        $admin->syncRoles([Role::Admin->value]);

        if (app()->isProduction() && ! config('clinic.admin.password')) {
            $this->command?->warn("Admin created: {$email} / {$password}  (shown once. Log in and change it.)");
        }
    }
}

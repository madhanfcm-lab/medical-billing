<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Roles, permissions and the first admin always run. Demo data (doctors, patients, bills, an EMI plan...)
     * is added everywhere except production; to load it there on purpose use
     * `php artisan db:seed --class=DemoDataSeeder --force`.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            AdminUserSeeder::class,
        ]);

        if (! app()->isProduction()) {
            $this->call(DemoDataSeeder::class);
        }
    }
}

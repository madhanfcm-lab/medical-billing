<?php

return [
    /*
    | First admin account created by the AdminUserSeeder. Read through config() (not env()) so it still
    | works when the configuration is cached in production.
    */
    'admin' => [
        'name' => env('ADMIN_NAME', 'Administrator'),
        'email' => env('ADMIN_EMAIL', 'admin@clinic.test'),
        'password' => env('ADMIN_PASSWORD'),
    ],
];

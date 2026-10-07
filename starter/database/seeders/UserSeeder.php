<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Users;
use Cast\Database\Seeder;
use Cast\Services\Auth;

/** The demo login: admin@example.com / password. */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        if (Users::exists('email', 'admin@example.com')) return;

        Users::query()->insert([
            'email' => 'admin@example.com',
            'password' => Auth::hash('password'),
            'permissions' => '["manage-items"]',
        ]);
    }
}

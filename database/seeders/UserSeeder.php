<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Users;
use Cast\Database\Seeder;
use Cast\Services\Auth;

/**
 * The demo login: admin@example.com / password. A seeder puts starting rows into tables; run them with  php cast db:seed  (or  migrate --seed).
 * `Users::exists('email', ...)` first makes it safe to run twice: the row is only created when it is missing. `Auth::hash()` stores a
 * hash of the password, never the password itself; `permissions` is a JSON list the Guard reads ('manage-items' allows deleting items).
 */
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

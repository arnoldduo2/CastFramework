<?php

declare(strict_types=1);

namespace Database\Seeders;

use Cast\Database\Seeder;

/** The seeder `php cast migrate --seed` and `php cast db:seed` run: it calls the others. Seeders put starting data into tables. Create one with  php cast make:seeder Name */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(UserSeeder::class);
    }
}

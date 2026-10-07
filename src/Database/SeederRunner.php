<?php

declare(strict_types=1);

namespace Cast\Database;

/** Runs a seeder class (default `Database\Seeders\DatabaseSeeder`, file `database/seeders/DatabaseSeeder.php`; `config('database.seeder')` changes the default). */
final class SeederRunner
{
    public static function run(?string $class = null): string
    {
        $class ??= (string) \Cast\Core\Config::get('database.seeder', 'DatabaseSeeder');
        $full = str_contains($class, '\\') ? ltrim($class, '\\') : 'Database\\Seeders\\' . $class;

        if (!class_exists($full)) {
            throw new MigrationException("Seeder $full was not found. Create it with: php cast make:seeder " . basename(str_replace('\\', '/', $full)));
        }
        if (!is_subclass_of($full, Seeder::class)) {
            throw new MigrationException("$full must extend " . Seeder::class . '.');
        }
        (new $full())->run();
        return $full;
    }
}

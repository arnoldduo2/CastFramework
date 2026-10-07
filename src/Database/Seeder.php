<?php

declare(strict_types=1);

namespace Cast\Database;

/** `database/seeders/DatabaseSeeder.php` extends this; `php cast db:seed` runs it. */
abstract class Seeder
{
    abstract public function run(): void;

    /** Run other seeders (class names, or short names in `Database\Seeders`). @param class-string|string|list<string> $classes */
    protected function call(string|array $classes): void
    {
        foreach ((array) $classes as $class) {
            $class = str_contains($class, '\\') ? $class : 'Database\\Seeders\\' . $class;
            if (!class_exists($class) || !is_subclass_of($class, self::class)) {
                throw new MigrationException("Seeder $class was not found.");
            }
            (new $class())->run();
        }
    }
}

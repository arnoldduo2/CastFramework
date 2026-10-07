<?php

declare(strict_types=1);

namespace Cast\Services;

use Cast\App\ServiceProvider;
use Cast\Contracts\Migrator as MigratorContract;
use Cast\Database\Migrator;

/**
 * Binds the migration runner (`migrator`). An app that uses another ORM binds its own adapter under the same key from
 * a provider listed in `app.providers` (those register after this one, so theirs wins).
 */
final class DatabaseProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('migrator', fn() => new Migrator($this->app));
        $this->app->singleton(MigratorContract::class, fn() => $this->app->make('migrator'));
    }
}

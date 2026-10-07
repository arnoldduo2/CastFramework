<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\UserStore;
use Cast\App\ServiceProvider;
use Cast\Services\Auth;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('auth', fn() => new Auth(new UserStore()));
    }

    public function boot(): void
    {
        $this->prepareDatabase();
        $this->app->make('view')->share('appName', (string) config('app.name'));
    }

    /** A relative SQLite file is relative to the app folder, and its folder must exist. Tables come from `php cast migrate`. */
    private function prepareDatabase(): void
    {
        $name = (string) config('database.name');
        if ($name === ':memory:' || config('database.driver') !== 'sqlite') return;

        if (!str_starts_with($name, '/') && !preg_match('#^[a-z]:[\\/]#i', $name)) {
            config(['database.name' => $this->app->basePath($name)]);
        }
        $dir = dirname((string) config('database.name'));
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
    }
}

<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\UserStore;
use Cast\App\ServiceProvider;
use Cast\Services\Auth;

/**
 * A service provider wires the app's services into the framework. It is listed in config/app.php ('providers').
 *   register()  runs first, for every provider: bind things into the container (`$this->app->singleton('name', fn() => ...)`);
 *               nothing may use another service yet.
 *   boot()      runs when every provider has registered: use services, share data with views, prepare things.
 * Get a bound service anywhere with  app('auth')  or  $this->app->make('view').  Copy this file to add your own (php cast make:provider is not needed: it is a plain class).
 */
final class AppServiceProvider extends ServiceProvider
{
    /** Bind the Auth service. It asks UserStore (app/Services/UserStore.php) where users live and how to find them. */
    public function register(): void
    {
        $this->app->singleton('auth', fn() => new Auth(new UserStore()));
    }

    /** Prepare the SQLite file, and give every view the app name as $appName. */
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

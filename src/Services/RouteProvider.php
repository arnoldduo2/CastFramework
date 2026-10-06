<?php

declare(strict_types=1);

namespace Cast\Services;

use Cast\App\ServiceProvider;

/** Loads every `*.php` file in the routes folder (route files call `Router::get(...)` etc.). */
final class RouteProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach (glob($this->app->routesPath('*.php')) ?: [] as $file) {
            require_once $file;
        }
    }
}

<?php

declare(strict_types=1);

namespace Cast\Services;

use Cast\App\ServiceProvider;
use Cast\Core\Config;
use Cast\Core\Router;

/**
 * Loads every `*.php` file in the routes folder (route files call `Router::get(...)` etc.).
 * `routes/api.php` is special: its routes are registered under the API prefix (`config('api.prefix')`, default `/api`)
 * and inside the `api.middleware` group, so `Router::get('/items', ...)` there answers `GET /api/items`.
 */
final class RouteProvider extends ServiceProvider
{
    public function boot(): void
    {
        $api = realpath($this->app->routesPath('api.php')) ?: null;

        foreach (glob($this->app->routesPath('*.php')) ?: [] as $file) {
            if ($api !== null && realpath($file) === $api) continue;
            require_once $file;
        }

        if ($api !== null) $this->loadApiRoutes($api);
    }

    private function loadApiRoutes(string $file): void
    {
        $load = static function () use ($file) {
            require_once $file;
        };

        // wrap from the inside out so the first listed middleware runs first
        foreach (array_reverse((array) Config::get('api.middleware', [])) as $spec) {
            $inner = $load;
            $load = static function () use ($spec, $inner) {
                Router::middleware((array) $spec, $inner);
            };
        }
        Router::group((string) Config::get('api.prefix', '/api'), $load);
    }
}

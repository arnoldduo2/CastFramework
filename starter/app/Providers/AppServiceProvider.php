<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\UserStore;
use Cast\App\ServiceProvider;
use Cast\Core\Database;
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

    /** Create the demo tables and a demo user on first run. */
    private function prepareDatabase(): void
    {
        $name = (string) config('database.name');
        if ($name !== ':memory:' && !str_starts_with($name, '/')) {
            config(['database.name' => $this->app->basePath($name)]);
        }
        $pdo = Database::connection();
        $pdo->exec('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT UNIQUE, password TEXT, permissions TEXT, last_login TEXT)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, qty INTEGER NOT NULL DEFAULT 0, price REAL NOT NULL DEFAULT 0, created_at TEXT)');

        if ((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
            $pdo->prepare('INSERT INTO users (email, password, permissions) VALUES (?, ?, ?)')
                ->execute(['admin@example.com', Auth::hash('password'), '["manage-items"]']);
        }
    }
}

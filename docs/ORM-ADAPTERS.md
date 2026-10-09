---
title: Using another ORM
section: Data
order: 5
description: Plug in Doctrine, Eloquent or Phinx for migrations, the connection and models.
---

# Using another ORM or migration tool

CastFramework's own `Model`, `QueryBuilder` and migrations are small on purpose. Everything the framework needs from the database layer goes through
three seams, so an app (or an adapter package) can bring Doctrine, Eloquent, Cycle, Phinx or anything else and keep `php cast migrate`, the API tokens,
validation and `ModelPersister` working.

| Seam | What it is | Replace it by |
| --- | --- | --- |
| The connection | `Cast\Core\Database::connection()`: one shared `PDO` | `config('database.connection')` = a callable that returns a `PDO` (or `Database::use($pdo)`) |
| The migration runner | `Cast\Contracts\Migrator`, container key `migrator` | Bind your own adapter under `migrator` in a service provider |
| The models | `Cast\Contracts\ModelContract` (+ `Savable`, `Patchable`, `HasLineItems`) | Implement the contract on your ORM's models |

## 1. Share the connection

The framework's own services that touch the database (API tokens, the query builder, validation `unique`/`exists`, the built-in migrator) all call
`Database::connection()`. Let your ORM own the connection and hand the framework its `PDO`:

```php
// config/database.php
return [
    'connection' => function () {
        // Doctrine DBAL 3/4:   return app('dbal')->getNativeConnection();
        // Eloquent (Capsule):  return Illuminate\Database\Capsule\Manager::connection()->getPdo();
        // Cycle / Atlas / plain PDO: return the PDO you already built
        return app('orm.pdo');
    },
];
```

The callable runs once, lazily, on first use. It must return a `PDO` (anything else is an `InvalidArgumentException`).

## 2. Bind your migrator

`php cast migrate`, `migrate:rollback`, `migrate:reset`, `migrate:refresh`, `migrate:fresh`, `migrate:status`, `migrate:baseline` and `make:migration` only call
`Cast\Contracts\Migrator`. Write a small adapter around your tool and bind it from a provider listed in `app.providers` (those register after the framework's own
providers, so yours wins):

```php
final class DoctrineMigrationsProvider extends Cast\App\ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('migrator', fn() => new DoctrineMigrator(/* DependencyFactory */));
    }
}
```

```php
final class DoctrineMigrator implements Cast\Contracts\Migrator
{
    public function migrate(array $options = []): array { /* run `migrations:migrate`; return the versions that ran */ }
    public function rollback(?int $step = null, bool $pretend = false): array { /* `migrations:migrate prev` ... */ }
    public function reset(): array { /* migrate to 'first' */ }
    public function refresh(): array { $this->reset(); return $this->migrate(); }
    public function fresh(): array { /* drop schema, then migrate */ }
    public function baseline(): array { /* mark pending migrations as run without running them */ }
    public function status(): array { /* [['migration' => 'Version20260101', 'ran' => true, 'batch' => null], ...] */ }
    public function make(string $name, ?string $create = null, ?string $table = null): string { /* generate a file, return its path */ }
    public function pretended(): array { /* ['Version...' => ['SQL...']] after a pretend run */ }
    public function onProgress(?callable $listener): void { /* call $listener('a line') while working, if you like */ }
}
```

`php cast migrate --pretend` prints `pretended()`; `--seed` still runs the framework's seeder (`database/seeders`), which only needs the models/connection, not the migrator.
Adapter packages (`anode/cast-doctrine`, `anode/cast-phinx`, ...) can ship exactly this class and provider: nothing in the core needs to change.

## 3. Use your ORM's models with the framework

The services that work with models depend on interfaces, not on `Cast\Core\Model`:

- `ModelPersister` saves/patches through `Savable`, `Patchable` and (for header + lines documents) `HasLineItems`.
- `Controller::getModelInstance('orders')` looks classes up in `config('models.namespace')`, so point it at your ORM's namespace.
- Validation `unique:table,column` / `exists:table,column` use the shared connection, so they work with whatever tables your ORM manages.

```php
final class Order extends Illuminate\Database\Eloquent\Model implements Cast\Contracts\Savable
{
    public function save(array $data = []): int|string|false { return static::create($data)->getKey() ?: false; }
}
```

## What stays the same

`php cast serve`, routing, requests, the SPA client and the JSON API do not care which ORM you use. The built-in `Model`/`QueryBuilder` can run next to another ORM
(for the API token table, for example) because they share the one connection.

<?php

declare(strict_types=1);

namespace Cast\Database;

use Cast\App\Application;
use Cast\Contracts\Migrator as MigratorContract;
use Cast\Core\Config;
use Cast\Core\Database;
use Cast\Core\QueryBuilder;
use PDO;
use Throwable;

/**
 * The built-in migration runner: files in `database/migrations`, a `migrations` table that records what ran and in which
 * batch, a lock so two deploys cannot migrate at once. Another ORM's migrator can replace it (bind `migrator`).
 */
final class Migrator implements MigratorContract
{
    /** @var array<string, Migration> files already loaded in this process (a file is only included once) */
    private static array $loaded = [];

    /** @var callable|null */
    private $listener = null;
    /** @var array<string, list<string>> */
    private array $pretended = [];

    public function __construct(private Application $app) {}

    public function onProgress(?callable $listener): void
    {
        $this->listener = $listener;
    }

    public function pretended(): array
    {
        return $this->pretended;
    }

    // ----------------------------------------------------------------- run

    public function migrate(array $options = []): array
    {
        return $this->locked(function () use ($options) {
            $this->ensureTable();
            $pending = $this->pending();
            if (!$pending) {
                $this->say('Nothing to migrate.');
                return [];
            }

            $pretend = (bool) ($options['pretend'] ?? false);
            $step = (bool) ($options['step'] ?? false);
            $batch = $this->lastBatch() + 1;
            $this->pretended = [];

            $ran = [];
            foreach ($pending as $name) {
                $pretend ? $this->pretendUp($name) : $this->runUp($name, $batch);
                $ran[] = $name;
                if ($step) $batch++;
            }
            return $ran;
        });
    }

    public function rollback(?int $step = null, bool $pretend = false): array
    {
        return $this->locked(function () use ($step, $pretend) {
            $this->ensureTable();
            $records = $this->ranRecords();
            $records = $step !== null
                ? array_slice($records, 0, max(0, $step))
                : array_values(array_filter($records, fn($r) => (int) $r['batch'] === ($records[0]['batch'] ?? -1)));

            if (!$records) {
                $this->say('Nothing to roll back.');
                return [];
            }
            $this->pretended = [];
            return $this->down($records, $pretend);
        });
    }

    public function reset(): array
    {
        return $this->locked(function () {
            $this->ensureTable();
            $records = $this->ranRecords();
            if (!$records) {
                $this->say('Nothing to roll back.');
                return [];
            }
            return $this->down($records, false);
        });
    }

    public function refresh(): array
    {
        $this->reset();
        return $this->migrate();
    }

    public function fresh(): array
    {
        $this->locked(function () {
            $this->say('Dropping all tables');
            $this->schema()->dropAllTables();
        });
        return $this->migrate();
    }

    public function baseline(): array
    {
        return $this->locked(function () {
            $this->ensureTable();
            $pending = $this->pending();
            $batch = $this->lastBatch() + 1;
            foreach ($pending as $name) {
                QueryBuilder::table($this->table())->insert(['migration' => $name, 'batch' => $batch, 'ran_at' => date('c')]);
                $this->say("Recorded:  $name");
            }
            return $pending;
        });
    }

    public function status(): array
    {
        $ran = [];
        if ($this->schema()->hasTable($this->table())) {
            foreach ($this->ranRecords() as $r) $ran[$r['migration']] = (int) $r['batch'];
        }

        $rows = [];
        foreach (array_keys($this->files()) as $name) {
            $rows[] = ['migration' => $name, 'ran' => isset($ran[$name]), 'batch' => $ran[$name] ?? null];
        }
        // migrations that ran but whose file is gone
        foreach ($ran as $name => $batch) {
            if (!isset($this->files()[$name])) $rows[] = ['migration' => $name, 'ran' => true, 'batch' => $batch];
        }
        return $rows;
    }

    // ---------------------------------------------------------------- make

    public function make(string $name, ?string $create = null, ?string $table = null): string
    {
        $name = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($name)), '_');
        if ($name === '' || !preg_match('/^[a-z]/', $name)) {
            throw new MigrationException('A migration name must start with a letter, like create_orders_table.');
        }

        $dir = $this->path();
        foreach (array_keys($this->files()) as $existing) {
            if (substr($existing, 18) === $name) throw new MigrationException("A migration named \"$name\" already exists ($existing).");
        }

        // the table comes from the name when it follows the usual pattern
        if ($create === null && $table === null) {
            if (preg_match('/^create_(.+)_table$/', $name, $m)) {
                $create = $m[1];
            } elseif (preg_match('/^(?:add|remove|drop|alter|update|change)_.+?_(?:to|from|in|on)_(.+)_table$/', $name, $m)) {
                $table = $m[1];
            }
        }
        foreach ([$create, $table] as $t) {
            if ($t !== null && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $t)) throw new MigrationException("\"$t\" is not a valid table name.");
        }

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) throw new MigrationException("Cannot create $dir.");

        $stamp = time();
        do {
            $file = $dir . DIRECTORY_SEPARATOR . date('Y_m_d_His', $stamp++) . '_' . $name . '.php';
        } while (is_file($file));

        file_put_contents($file, $this->stub($create, $table));
        return $file;
    }

    private function stub(?string $create, ?string $table): string
    {
        $head = "<?php\n\ndeclare(strict_types=1);\n\nuse Cast\\Database\\Blueprint;\nuse Cast\\Database\\Migration;\nuse Cast\\Database\\Schema;\n\nreturn new class extends Migration\n{\n";
        if ($create !== null) {
            return $head . "    public function up(Schema \$schema): void\n    {\n        \$schema->create('$create', function (Blueprint \$table) {\n            \$table->id();\n            \$table->timestamps();\n        });\n    }\n\n"
                . "    public function down(Schema \$schema): void\n    {\n        \$schema->dropIfExists('$create');\n    }\n};\n";
        }
        if ($table !== null) {
            return $head . "    public function up(Schema \$schema): void\n    {\n        \$schema->table('$table', function (Blueprint \$table) {\n            //\n        });\n    }\n\n"
                . "    public function down(Schema \$schema): void\n    {\n        \$schema->table('$table', function (Blueprint \$table) {\n            //\n        });\n    }\n};\n";
        }
        return $head . "    public function up(Schema \$schema): void\n    {\n        //\n    }\n\n    public function down(Schema \$schema): void\n    {\n        //\n    }\n};\n";
    }

    // ------------------------------------------------------------ internals

    private function runUp(string $name, int $batch): void
    {
        $migration = $this->load($name);
        $this->say("Migrating: $name");
        $started = microtime(true);

        $this->inTransaction($name, $migration, function () use ($migration, $name, $batch) {
            $migration->up($this->schema());
            QueryBuilder::table($this->table())->insert(['migration' => $name, 'batch' => $batch, 'ran_at' => date('c')]);
        });
        $this->say(sprintf('Migrated:  %s (%d ms)', $name, (microtime(true) - $started) * 1000));
    }

    private function pretendUp(string $name): void
    {
        $migration = $this->load($name);
        $this->pretended[$name] = $this->schema()->pretend(fn(Schema $s) => $migration->up($s));
        $this->say("Would migrate: $name");
    }

    /**
     * @param list<array<string, mixed>> $records newest first
     * @return list<string>
     */
    private function down(array $records, bool $pretend): array
    {
        $names = [];
        foreach ($records as $record) {
            $name = (string) $record['migration'];
            $migration = $this->load($name);

            if ($pretend) {
                $this->pretended[$name] = $this->schema()->pretend(fn(Schema $s) => $migration->down($s));
                $this->say("Would roll back: $name");
            } else {
                $this->say("Rolling back: $name");
                $started = microtime(true);
                $this->inTransaction($name, $migration, function () use ($migration, $record) {
                    $migration->down($this->schema());
                    QueryBuilder::table($this->table())->where('id', $record['id'])->delete();
                });
                $this->say(sprintf('Rolled back:  %s (%d ms)', $name, (microtime(true) - $started) * 1000));
            }
            $names[] = $name;
        }
        return $names;
    }

    private function inTransaction(string $name, Migration $migration, callable $work): void
    {
        $pdo = $this->pdo();
        // PostgreSQL and SQLite can roll DDL back; MySQL commits every DDL statement by itself
        $use = $migration->transactional && in_array($pdo->getAttribute(PDO::ATTR_DRIVER_NAME), ['pgsql', 'sqlite'], true) && !$pdo->inTransaction();

        try {
            if ($use) $pdo->beginTransaction();
            $work();
            if ($use && $pdo->inTransaction()) $pdo->commit();
        } catch (Throwable $e) {
            if ($use && $pdo->inTransaction()) $pdo->rollBack();
            throw new MigrationException("Migration $name failed: " . $e->getMessage(), 0, $e);
        }
    }

    private function load(string $name): Migration
    {
        $path = $this->path() . DIRECTORY_SEPARATOR . $name . '.php';
        if (!is_file($path)) throw new MigrationException("The migration file for \"$name\" is missing ($path).");

        $key = $path . '|' . filemtime($path);
        if (isset(self::$loaded[$key])) return self::$loaded[$key];

        $migration = require $path;
        if (!$migration instanceof Migration) {
            throw new MigrationException("$name.php must return an object that extends " . Migration::class . '.');
        }
        return self::$loaded[$key] = $migration;
    }

    /** @return array<string, string> name => path, oldest first */
    private function files(): array
    {
        $files = [];
        foreach (glob($this->path() . DIRECTORY_SEPARATOR . '*.php') ?: [] as $path) {
            $files[basename($path, '.php')] = $path;
        }
        ksort($files);
        return $files;
    }

    /** @return list<string> */
    private function pending(): array
    {
        $ran = array_column($this->ranRecords(), 'migration');
        return array_values(array_diff(array_keys($this->files()), $ran));
    }

    /** @return list<array{id: mixed, migration: string, batch: mixed}> newest first */
    private function ranRecords(): array
    {
        return QueryBuilder::table($this->table())->orderBy('id', 'DESC')->get();
    }

    private function lastBatch(): int
    {
        $row = $this->pdo()->query('SELECT MAX(batch) FROM ' . $this->schema()->grammar()->id($this->table()))->fetchColumn();
        return (int) $row;
    }

    private function ensureTable(): void
    {
        $schema = $this->schema();
        if ($schema->hasTable($this->table())) return;

        $schema->create($this->table(), function (Blueprint $t) {
            $t->id();
            $t->string('migration')->unique();
            $t->integer('batch');
            $t->string('ran_at', 40);
        });
    }

    /** Only one migration run at a time (a lock file under storage/). */
    private function locked(callable $work): mixed
    {
        $dir = $this->app->storagePath('framework');
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $handle = @fopen($dir . DIRECTORY_SEPARATOR . 'migrate.lock', 'c');

        if ($handle !== false && !flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new MigrationException('Another migration is running. Wait for it to finish.');
        }
        try {
            return $work();
        } finally {
            if ($handle !== false) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }

    private function say(string $line): void
    {
        if ($this->listener) ($this->listener)($line);
    }

    private function pdo(): PDO
    {
        return Database::connection();
    }

    private function schema(): Schema
    {
        return new Schema($this->pdo());
    }

    private function table(): string
    {
        return (string) Config::get('database.migrations.table', 'migrations');
    }

    private function path(): string
    {
        $path = Config::get('database.migrations.path');
        return is_string($path) && $path !== '' ? (preg_match('#^([a-z]:)?[\\/]#i', $path) ? $path : $this->app->basePath($path)) : $this->app->databasePath('migrations');
    }
}

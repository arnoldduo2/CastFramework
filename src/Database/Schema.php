<?php

declare(strict_types=1);

namespace Cast\Database;

use Cast\Database\Grammars\Grammar;
use Cast\Database\Grammars\MySqlGrammar;
use Cast\Database\Grammars\PostgresGrammar;
use Cast\Database\Grammars\SqliteGrammar;
use Closure;
use InvalidArgumentException;
use PDO;

/**
 * Creates and changes tables with the same code on MySQL, PostgreSQL and SQLite:
 *
 *   $schema->create('items', function (Blueprint $table) { $table->id(); $table->string('name'); });
 *   $schema->table('items', function (Blueprint $table) { $table->integer('qty')->default(0); });
 *   $schema->dropIfExists('items');
 *   $schema->statement('CREATE VIEW ...');      // anything the builder cannot say
 */
final class Schema
{
    private Grammar $grammar;
    /** @var list<string>|null SQL collected instead of run while pretending */
    private ?array $pretend = null;

    public function __construct(private PDO $pdo, ?Grammar $grammar = null)
    {
        $this->grammar = $grammar ?? self::grammarFor((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    }

    public static function grammarFor(string $driver): Grammar
    {
        return match ($driver) {
            'mysql' => new MySqlGrammar(),
            'pgsql' => new PostgresGrammar(),
            'sqlite' => new SqliteGrammar(),
            default => throw new InvalidArgumentException("Migrations do not support the \"$driver\" database. Bind your own `migrator` (see docs/ORM-ADAPTERS.md)."),
        };
    }

    public function grammar(): Grammar
    {
        return $this->grammar;
    }

    public function create(string $table, Closure $callback): void
    {
        $blueprint = new Blueprint($table, true);
        $callback($blueprint);
        $this->run($this->grammar->compileCreate($blueprint));
    }

    public function table(string $table, Closure $callback): void
    {
        $blueprint = new Blueprint($table, false);
        $callback($blueprint);
        $this->run($this->grammar->compileAlter($blueprint));
    }

    public function drop(string $table): void
    {
        $this->run([$this->grammar->compileDrop($table)]);
    }

    public function dropIfExists(string $table): void
    {
        $this->run([$this->grammar->compileDrop($table, true)]);
    }

    public function rename(string $from, string $to): void
    {
        $this->run([$this->grammar->compileRename($from, $to)]);
    }

    public function hasTable(string $table): bool
    {
        $stmt = $this->pdo->prepare($this->grammar->compileHasTable());
        $stmt->execute([$table]);
        return $stmt->fetchColumn() !== false;
    }

    public function hasColumn(string $table, string $column): bool
    {
        return in_array(strtolower($column), array_map('strtolower', $this->columns($table)), true);
    }

    /** @return list<string> */
    public function columns(string $table): array
    {
        [$sql, $bindings] = $this->grammar->compileListColumns($table);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<string> */
    public function tables(): array
    {
        return array_map('strval', $this->pdo->query($this->grammar->compileListTables())->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Drop every table (used by `migrate:fresh`). */
    public function dropAllTables(): void
    {
        $this->run($this->grammar->compileDropAll($this->tables()));
    }

    /** Run raw SQL for what the builder cannot say. Use `?` placeholders for values. @param list<mixed> $bindings */
    public function statement(string $sql, array $bindings = []): void
    {
        if ($this->pretend !== null) {
            $this->pretend[] = $sql;
            return;
        }
        if ($bindings) {
            $this->pdo->prepare($sql)->execute($bindings);
        } else {
            $this->pdo->exec($sql);
        }
    }

    /** A default value that is SQL: `->default(Schema::raw('CURRENT_TIMESTAMP'))`. */
    public static function raw(string $sql): Raw
    {
        return new Raw($sql);
    }

    /**
     * Run `$callback` without changing anything and return the SQL it would have run.
     * @return list<string>
     */
    public function pretend(Closure $callback): array
    {
        $this->pretend = [];
        try {
            $callback($this);
            return $this->pretend;
        } finally {
            $this->pretend = null;
        }
    }

    /** @param list<string> $statements */
    private function run(array $statements): void
    {
        foreach ($statements as $sql) $this->statement($sql);
    }
}

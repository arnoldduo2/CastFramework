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

    /** The auto-incrementing column of a table, or null. */
    public function autoIncrementColumn(string $table): ?string
    {
        switch ($this->grammar->driver()) {
            case 'mysql':
                $stmt = $this->pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND LOWER(extra) LIKE '%auto_increment%'");
                break;
            case 'pgsql':
                $stmt = $this->pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND (is_identity = 'YES' OR column_default LIKE 'nextval(%') ORDER BY ordinal_position");
                break;
            default:
                $stmt = $this->pdo->prepare('SELECT name FROM pragma_table_info(?) WHERE pk > 0 AND LOWER(type) = \'integer\' AND (SELECT COUNT(*) FROM pragma_table_info(?) WHERE pk > 0) = 1');
                $stmt->execute([$table, $table]);
                $column = $stmt->fetchColumn();
                return $column === false ? null : (string) $column;
        }
        $stmt->execute([$table]);
        $column = $stmt->fetchColumn();
        return $column === false ? null : (string) $column;
    }

    /** The value the next inserted row will get in the auto-increment column, or null when the table has none (or no position is stored yet). */
    public function nextAutoIncrement(string $table): ?int
    {
        $column = $this->autoIncrementColumn($table);
        if ($column === null) return null;
        switch ($this->grammar->driver()) {
            case 'mysql':
                // MySQL 8 caches table statistics for a day; the counter must be read fresh (MariaDB has no such setting)
                try {
                    $this->pdo->exec('SET SESSION information_schema_stats_expiry = 0');
                } catch (\PDOException) {
                }
                $stmt = $this->pdo->prepare('SELECT auto_increment FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
                $stmt->execute([$table]);
                $value = $stmt->fetchColumn();
                return $value === false || $value === null ? 1 : (int) $value;
            case 'pgsql':
                $seq = $this->sequenceOf($table, $column);
                if ($seq === null) return null;
                $row = $this->pdo->query('SELECT last_value, is_called FROM ' . $seq)->fetch(PDO::FETCH_ASSOC);
                return (int) $row['last_value'] + ($row['is_called'] ? 1 : 0);
            default:
                try {
                    $stmt = $this->pdo->prepare('SELECT seq FROM sqlite_sequence WHERE name = ?');
                    $stmt->execute([$table]);
                    $seq = $stmt->fetchColumn();
                } catch (\PDOException) {
                    return 1;   // no AUTOINCREMENT table has been used yet: the table does not exist
                }
                return $seq === false ? 1 : (int) $seq + 1;
        }
    }

    /** Make the next inserted row get `$next` (never lowers below what the engine allows). */
    public function autoIncrement(string $table, int $next): void
    {
        $column = $this->autoIncrementColumn($table);
        if ($column === null) throw new MigrationException("Table \"$table\" has no auto-increment column.");
        if ($next < 1) throw new MigrationException('The next auto-increment value must be 1 or more.');
        switch ($this->grammar->driver()) {
            case 'mysql':
                $this->pdo->exec('ALTER TABLE ' . $this->grammar->id($table) . ' AUTO_INCREMENT = ' . $next);
                return;
            case 'pgsql':
                $seq = $this->sequenceOf($table, $column);
                if ($seq === null) throw new MigrationException("Column \"$column\" of \"$table\" has no sequence.");
                $this->pdo->exec('SELECT setval(' . $this->pdo->quote(trim($seq)) . ', ' . $next . ', false)');
                return;
            default:
                $stmt = $this->pdo->prepare('UPDATE sqlite_sequence SET seq = ? WHERE name = ?');
                $stmt->execute([$next - 1, $table]);
                if ($stmt->rowCount() === 0) {
                    $this->pdo->prepare('INSERT INTO sqlite_sequence (name, seq) VALUES (?, ?)')->execute([$table, $next - 1]);
                }
        }
    }

    private function sequenceOf(string $table, string $column): ?string
    {
        $stmt = $this->pdo->prepare('SELECT pg_get_serial_sequence(?, ?)');
        $stmt->execute([$this->grammar->id($table), $column]);
        $seq = $stmt->fetchColumn();
        return is_string($seq) && $seq !== '' ? $seq : null;
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

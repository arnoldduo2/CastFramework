<?php

declare(strict_types=1);

namespace Cast\Database;

use InvalidArgumentException;

/**
 * The columns, indexes and foreign keys of one table: the argument of the closures given to
 * `Schema::create()` and `Schema::table()`.
 */
final class Blueprint
{
    /** @var list<Column> */
    public array $columns = [];
    /** @var list<array{type: string, columns: list<string>, name: ?string}> index, unique, primary, dropIndex, dropUnique, dropPrimary, dropColumn */
    public array $commands = [];
    /** @var list<array{from: string, to: string}> */
    public array $renames = [];
    /** @var list<ForeignKey> */
    public array $foreignKeys = [];
    /** @var list<string> */
    public array $droppedForeignKeys = [];

    /** MySQL table character set and collation (ignored elsewhere); null = the grammar's default. */
    public ?string $charset = null;
    public ?string $collation = null;

    public function __construct(public readonly string $table, public readonly bool $creating) {}

    public function charset(string $charset): self
    {
        $this->charset = self::collationName($charset);
        return $this;
    }

    /** Also sets the character set when it was not given (utf8mb4_unicode_ci => utf8mb4). */
    public function collation(string $collation): self
    {
        $this->collation = self::collationName($collation);
        $this->charset ??= strtok($this->collation, '_') ?: null;
        return $this;
    }

    /** @internal */
    public static function collationName(string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9_.\-]+$/', $name)) throw new \InvalidArgumentException("\"$name\" is not a valid charset or collation name.");
        return $name;
    }

    // ---------------------------------------------------------------- columns

    /** An auto-incrementing big integer primary key. */
    public function id(string $name = 'id'): Column
    {
        $column = $this->add('bigInteger', $name);
        $column->autoIncrement = true;
        $column->primary = true;
        $column->unsigned = true;
        return $column;
    }

    public function increments(string $name = 'id'): Column
    {
        $column = $this->add('integer', $name);
        $column->autoIncrement = true;
        $column->primary = true;
        $column->unsigned = true;
        return $column;
    }

    public function string(string $name, int $length = 255): Column
    {
        return $this->add('string', $name, ['length' => $this->positive($length)]);
    }

    public function char(string $name, int $length = 255): Column
    {
        return $this->add('char', $name, ['length' => $this->positive($length)]);
    }

    public function text(string $name): Column
    {
        return $this->add('text', $name);
    }

    public function mediumText(string $name): Column
    {
        return $this->add('mediumText', $name);
    }

    public function longText(string $name): Column
    {
        return $this->add('longText', $name);
    }

    public function integer(string $name): Column
    {
        return $this->add('integer', $name);
    }

    public function bigInteger(string $name): Column
    {
        return $this->add('bigInteger', $name);
    }

    public function smallInteger(string $name): Column
    {
        return $this->add('smallInteger', $name);
    }

    public function tinyInteger(string $name): Column
    {
        return $this->add('tinyInteger', $name);
    }

    public function boolean(string $name): Column
    {
        return $this->add('boolean', $name);
    }

    public function decimal(string $name, int $precision = 10, int $scale = 2): Column
    {
        return $this->add('decimal', $name, ['precision' => $this->positive($precision), 'scale' => max(0, $scale)]);
    }

    public function float(string $name): Column
    {
        return $this->add('float', $name);
    }

    public function double(string $name): Column
    {
        return $this->add('double', $name);
    }

    public function date(string $name): Column
    {
        return $this->add('date', $name);
    }

    public function time(string $name): Column
    {
        return $this->add('time', $name);
    }

    public function dateTime(string $name): Column
    {
        return $this->add('dateTime', $name);
    }

    public function timestamp(string $name): Column
    {
        return $this->add('timestamp', $name);
    }

    /** `created_at` and `updated_at`, both nullable timestamps. */
    public function timestamps(): void
    {
        $this->timestamp('created_at')->nullable();
        $this->timestamp('updated_at')->nullable();
    }

    public function softDeletes(string $name = 'deleted_at'): Column
    {
        return $this->timestamp($name)->nullable();
    }

    public function json(string $name): Column
    {
        return $this->add('json', $name);
    }

    public function uuid(string $name): Column
    {
        return $this->add('uuid', $name);
    }

    public function binary(string $name): Column
    {
        return $this->add('binary', $name);
    }

    /** @param list<string> $values */
    public function enum(string $name, array $values): Column
    {
        if (!$values) throw new InvalidArgumentException('An enum column needs at least one value.');
        return $this->add('enum', $name, ['values' => array_values(array_map('strval', $values))]);
    }

    /** An unsigned big integer for a foreign key: chain `->constrained()` to add the constraint. */
    public function foreignId(string $name): Column
    {
        return $this->add('bigInteger', $name)->unsigned();
    }

    // ------------------------------------------------------- indexes and keys

    /** @param string|list<string> $columns */
    public function index(string|array $columns, ?string $name = null): self
    {
        $this->commands[] = ['type' => 'index', 'columns' => (array) $columns, 'name' => $name];
        return $this;
    }

    /** @param string|list<string> $columns */
    public function unique(string|array $columns, ?string $name = null): self
    {
        $this->commands[] = ['type' => 'unique', 'columns' => (array) $columns, 'name' => $name];
        return $this;
    }

    /** @param string|list<string> $columns */
    public function primary(string|array $columns): self
    {
        $this->commands[] = ['type' => 'primary', 'columns' => (array) $columns, 'name' => null];
        return $this;
    }

    /** @param string|list<string> $columns */
    public function foreign(string|array $columns, ?string $name = null): ForeignKey
    {
        return $this->foreignKeys[] = new ForeignKey(array_values((array) $columns), $name);
    }

    // ---------------------------------------------------------- change tables

    /** @param string|list<string> $columns */
    public function dropColumn(string|array $columns): self
    {
        $this->commands[] = ['type' => 'dropColumn', 'columns' => (array) $columns, 'name' => null];
        return $this;
    }

    public function renameColumn(string $from, string $to): self
    {
        $this->renames[] = ['from' => $from, 'to' => $to];
        return $this;
    }

    /** @param string|list<string> $columnsOrName the index name, or the columns it was made on (the default name is derived) */
    public function dropIndex(string|array $columnsOrName): self
    {
        $this->commands[] = ['type' => 'dropIndex', 'columns' => (array) $columnsOrName, 'name' => is_string($columnsOrName) ? $columnsOrName : null];
        return $this;
    }

    /** @param string|list<string> $columnsOrName */
    public function dropUnique(string|array $columnsOrName): self
    {
        $this->commands[] = ['type' => 'dropUnique', 'columns' => (array) $columnsOrName, 'name' => is_string($columnsOrName) ? $columnsOrName : null];
        return $this;
    }

    public function dropPrimary(): self
    {
        $this->commands[] = ['type' => 'dropPrimary', 'columns' => [], 'name' => null];
        return $this;
    }

    /** @param string|list<string> $columnsOrName the constraint name, or the columns it was made on */
    public function dropForeign(string|array $columnsOrName): self
    {
        $this->droppedForeignKeys[] = is_array($columnsOrName) ? self::indexName($this->table, $columnsOrName, 'foreign') : $columnsOrName;
        return $this;
    }

    // ---------------------------------------------------------------- helpers

    /** The default name of an index: `{table}_{columns}_{type}`, shortened with a hash when too long for the databases (64 characters). */
    public static function indexName(string $table, array $columns, string $type): string
    {
        $name = strtolower($table . '_' . implode('_', $columns) . '_' . $type);
        $name = (string) preg_replace('/[^a-z0-9_]/', '_', $name);
        return strlen($name) <= 60 ? $name : substr($name, 0, 51) . '_' . substr(md5($name), 0, 8);
    }

    private function add(string $type, string $name, array $attributes = []): Column
    {
        return $this->columns[] = new Column($this, $type, $name, $attributes);
    }

    private function positive(int $value): int
    {
        if ($value < 1) throw new InvalidArgumentException('A length must be at least 1.');
        return $value;
    }
}

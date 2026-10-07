<?php

declare(strict_types=1);

namespace Cast\Database\Grammars;

use Cast\Database\Blueprint;
use Cast\Database\Column;
use Cast\Database\ForeignKey;
use Cast\Database\MigrationException;
use Cast\Database\Raw;
use InvalidArgumentException;

/**
 * Turns a {@see Blueprint} into SQL for one database. Names are checked (`[A-Za-z_][A-Za-z0-9_]*`) and quoted, values
 * are escaped; nothing from a Blueprint is ever put into SQL unchecked.
 */
abstract class Grammar
{
    abstract public function driver(): string;

    /** The quote character for identifiers. */
    abstract protected function quoteChar(): string;

    /** The SQL type of a column (without NULL/DEFAULT). */
    abstract protected function type(Column $column): string;

    /** The type of an auto-incrementing primary key column, including its key clause. */
    abstract protected function incrementing(Column $column): string;

    // ------------------------------------------------------------ quoting

    public function id(string $name): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new InvalidArgumentException("Invalid identifier: \"$name\"");
        }
        $q = $this->quoteChar();
        return $q . $name . $q;
    }

    /** @param list<string> $names */
    public function ids(array $names): string
    {
        if (!$names) throw new InvalidArgumentException('At least one column is needed.');
        return implode(', ', array_map(fn($n) => $this->id((string) $n), $names));
    }

    public function value(mixed $value): string
    {
        if ($value instanceof Raw) return $value->sql;
        if ($value === null) return 'NULL';
        if (is_bool($value)) return $this->bool($value);
        if (is_int($value)) return (string) $value;
        if (is_float($value)) {
            if (!is_finite($value)) throw new InvalidArgumentException('A default value must be a finite number.');
            return rtrim(rtrim(sprintf('%.14F', $value), '0'), '.') ?: '0';
        }
        $value = (string) $value;
        if (str_contains($value, "\0")) throw new InvalidArgumentException('A value cannot contain a NUL byte.');
        return "'" . $this->escape($value) . "'";
    }

    protected function bool(bool $value): string
    {
        return $value ? '1' : '0';
    }

    protected function escape(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    // ------------------------------------------------------------- columns

    public function column(Column $column): string
    {
        $sql = $this->id($column->name) . ' ';

        if ($column->autoIncrement) {
            return $sql . $this->incrementing($column) . $this->commentClause($column);
        }

        $sql .= $this->type($column) . $this->unsignedClause($column) . $this->collationClause($column);
        $sql .= $column->nullable ? ' NULL' : ' NOT NULL';
        $sql .= $this->defaultClause($column);
        $sql .= $this->extra($column);
        if ($column->primary) $sql .= ' PRIMARY KEY';
        return $sql . $this->commentClause($column);
    }

    protected function collationClause(Column $column): string
    {
        return $column->collation !== null ? ' COLLATE ' . $this->collationName($column->collation) : '';
    }

    protected function collationName(string $name): string
    {
        return $name;
    }

    protected function unsignedClause(Column $column): string
    {
        return '';
    }

    protected function defaultClause(Column $column): string
    {
        if ($column->useCurrent) return ' DEFAULT CURRENT_TIMESTAMP';
        return $column->hasDefault ? ' DEFAULT ' . $this->value($column->default) : '';
    }

    /** Something after the default: a CHECK constraint for enums where the database has no ENUM type. */
    protected function extra(Column $column): string
    {
        return '';
    }

    protected function commentClause(Column $column): string
    {
        return '';
    }

    protected function enumCheck(Column $column): string
    {
        $values = implode(', ', array_map(fn($v) => $this->value($v), (array) $column->attributes['values']));
        return ' CHECK (' . $this->id($column->name) . ' IN (' . $values . '))';
    }

    // -------------------------------------------------------------- tables

    /** @return list<string> */
    public function compileCreate(Blueprint $bp): array
    {
        $table = $this->id($bp->table);
        $lines = array_map(fn(Column $c) => $this->column($c), $bp->columns);

        foreach ($bp->commands as $command) {
            if ($command['type'] === 'primary') $lines[] = 'PRIMARY KEY (' . $this->ids($command['columns']) . ')';
        }
        foreach ($bp->foreignKeys as $fk) {
            $lines[] = $this->foreignKey($bp->table, $fk);
        }
        if (!$lines) throw new MigrationException("Table \"{$bp->table}\" has no columns.");

        $statements = ["CREATE TABLE $table (\n    " . implode(",\n    ", $lines) . "\n)" . $this->tableOptions($bp)];
        foreach ($this->createIndexes($bp) as $sql) $statements[] = $sql;
        foreach ($this->afterCreate($bp) as $sql) $statements[] = $sql;
        return $statements;
    }

    /** @return list<string> */
    public function compileAlter(Blueprint $bp): array
    {
        $t = $this->id($bp->table);
        $out = [];

        foreach ($bp->droppedForeignKeys as $name) $out[] = $this->dropForeignKey($bp->table, $name);
        foreach ($bp->commands as $c) {
            match ($c['type']) {
                'dropIndex' => $out[] = $this->dropIndex($bp->table, $c['name'] ?? Blueprint::indexName($bp->table, $c['columns'], 'index')),
                'dropUnique' => $out[] = $this->dropIndex($bp->table, $c['name'] ?? Blueprint::indexName($bp->table, $c['columns'], 'unique')),
                'dropPrimary' => $out[] = $this->dropPrimary($bp->table),
                default => null,
            };
        }
        foreach ($bp->renames as $r) $out[] = "ALTER TABLE $t RENAME COLUMN " . $this->id($r['from']) . ' TO ' . $this->id($r['to']);
        foreach ($bp->commands as $c) {
            if ($c['type'] === 'dropColumn') {
                foreach ($c['columns'] as $col) $out[] = "ALTER TABLE $t DROP COLUMN " . $this->id($col);
            }
        }
        foreach ($bp->columns as $col) {
            $out[] = "ALTER TABLE $t ADD COLUMN " . $this->column($col) . $this->afterClause($col);
            foreach ($this->afterAddColumn($bp, $col) as $sql) $out[] = $sql;
        }
        foreach ($bp->commands as $c) {
            if ($c['type'] === 'primary') $out[] = $this->addPrimary($bp->table, $c['columns']);
        }
        foreach ($this->createIndexes($bp) as $sql) $out[] = $sql;
        foreach ($bp->foreignKeys as $fk) $out[] = "ALTER TABLE $t ADD " . $this->foreignKey($bp->table, $fk);
        return $out;
    }

    /** @return list<string> */
    protected function createIndexes(Blueprint $bp): array
    {
        $out = [];
        foreach ($bp->commands as $c) {
            if (!in_array($c['type'], ['index', 'unique'], true)) continue;
            $name = $c['name'] ?? Blueprint::indexName($bp->table, $c['columns'], $c['type']);
            $out[] = 'CREATE ' . ($c['type'] === 'unique' ? 'UNIQUE ' : '') . 'INDEX ' . $this->id($name)
                . ' ON ' . $this->id($bp->table) . ' (' . $this->ids($c['columns']) . ')';
        }
        return $out;
    }

    protected function foreignKey(string $table, ForeignKey $fk): string
    {
        if ($fk->referencedTable === '') {
            throw new MigrationException('A foreign key on (' . implode(', ', $fk->columns) . ") of \"$table\" needs ->on('table').");
        }
        $name = $fk->name ?? Blueprint::indexName($table, $fk->columns, 'foreign');
        $sql = 'CONSTRAINT ' . $this->id($name) . ' FOREIGN KEY (' . $this->ids($fk->columns) . ') REFERENCES '
            . $this->id($fk->referencedTable) . ' (' . $this->ids($fk->referencedColumns) . ')';
        if ($fk->onDelete) $sql .= ' ON DELETE ' . $fk->onDelete;
        if ($fk->onUpdate) $sql .= ' ON UPDATE ' . $fk->onUpdate;
        return $sql;
    }

    protected function tableOptions(Blueprint $bp): string
    {
        return '';
    }

    /** @return list<string> */
    protected function afterCreate(Blueprint $bp): array
    {
        return [];
    }

    /** @return list<string> */
    protected function afterAddColumn(Blueprint $bp, Column $column): array
    {
        return [];
    }

    protected function afterClause(Column $column): string
    {
        return '';
    }

    abstract protected function dropIndex(string $table, string $name): string;

    abstract protected function dropForeignKey(string $table, string $name): string;

    abstract protected function dropPrimary(string $table): string;

    /** @param list<string> $columns */
    protected function addPrimary(string $table, array $columns): string
    {
        return 'ALTER TABLE ' . $this->id($table) . ' ADD PRIMARY KEY (' . $this->ids($columns) . ')';
    }

    public function compileDrop(string $table, bool $ifExists = false): string
    {
        return 'DROP TABLE ' . ($ifExists ? 'IF EXISTS ' : '') . $this->id($table);
    }

    public function compileRename(string $from, string $to): string
    {
        return 'ALTER TABLE ' . $this->id($from) . ' RENAME TO ' . $this->id($to);
    }

    /** SQL (with one `?`) that returns a row when the table exists. */
    abstract public function compileHasTable(): string;

    /** @return list<string> the table names, from a query run by the Schema */
    abstract public function compileListTables(): string;

    /** @param list<string> $tables @return list<string> statements that drop all of them, whatever their foreign keys */
    abstract public function compileDropAll(array $tables): array;

    /** @return array{string, list<mixed>} SQL and bindings listing the column names of a table (first column of each row) */
    abstract public function compileListColumns(string $table): array;
}

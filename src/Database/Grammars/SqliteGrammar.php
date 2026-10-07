<?php

declare(strict_types=1);

namespace Cast\Database\Grammars;

use Cast\Database\Blueprint;
use Cast\Database\Column;
use Cast\Database\MigrationException;

final class SqliteGrammar extends Grammar
{
    public function driver(): string
    {
        return 'sqlite';
    }

    protected function quoteChar(): string
    {
        return '"';
    }

    protected function type(Column $c): string
    {
        $a = $c->attributes;
        return match ($c->type) {
            'string' => 'VARCHAR(' . $a['length'] . ')',
            'char' => 'CHAR(' . $a['length'] . ')',
            'text', 'mediumText', 'longText' => 'TEXT',
            'integer', 'bigInteger', 'smallInteger', 'tinyInteger', 'boolean' => 'INTEGER',
            'decimal' => 'NUMERIC(' . $a['precision'] . ', ' . $a['scale'] . ')',
            'float', 'double' => 'REAL',
            'date' => 'DATE',
            'time' => 'TIME',
            'dateTime' => 'DATETIME',
            'timestamp' => 'DATETIME',
            'json' => 'TEXT',
            'uuid' => 'VARCHAR(36)',
            'binary' => 'BLOB',
            'enum' => 'VARCHAR(255)',
        };
    }

    protected function incrementing(Column $c): string
    {
        return 'INTEGER PRIMARY KEY AUTOINCREMENT';
    }

    protected function extra(Column $c): string
    {
        return $c->type === 'enum' ? $this->enumCheck($c) : '';
    }

    protected function dropIndex(string $table, string $name): string
    {
        return 'DROP INDEX ' . $this->id($name);
    }

    protected function dropForeignKey(string $table, string $name): string
    {
        throw new MigrationException('SQLite cannot drop a foreign key from an existing table. Rebuild the table, or use $schema->statement().');
    }

    protected function dropPrimary(string $table): string
    {
        throw new MigrationException('SQLite cannot drop a primary key from an existing table. Rebuild the table, or use $schema->statement().');
    }

    protected function addPrimary(string $table, array $columns): string
    {
        throw new MigrationException('SQLite cannot add a primary key to an existing table. Define it when the table is created.');
    }

    public function compileAlter(Blueprint $bp): array
    {
        if ($bp->foreignKeys) {
            throw new MigrationException('SQLite cannot add a foreign key to an existing table. Define it when the table is created.');
        }
        foreach ($bp->columns as $c) {
            if ($c->autoIncrement || $c->primary) {
                throw new MigrationException('SQLite cannot add a primary key column to an existing table.');
            }
            if (!$c->nullable && !$c->hasDefault && !$c->useCurrent) {
                throw new MigrationException("SQLite needs a default value (or nullable()) to add the NOT NULL column \"{$c->name}\" to an existing table.");
            }
        }
        return parent::compileAlter($bp);
    }

    public function compileHasTable(): string
    {
        return "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?";
    }

    public function compileListTables(): string
    {
        return "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'";
    }

    public function compileDropAll(array $tables): array
    {
        $out = ['PRAGMA foreign_keys = OFF'];
        foreach ($tables as $t) $out[] = 'DROP TABLE ' . $this->id($t);
        $out[] = 'PRAGMA foreign_keys = ON';
        return $tables ? $out : [];
    }

    public function compileListColumns(string $table): array
    {
        return ['SELECT name FROM pragma_table_info(?)', [$table]];
    }
}

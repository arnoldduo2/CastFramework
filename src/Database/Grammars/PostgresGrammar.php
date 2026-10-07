<?php

declare(strict_types=1);

namespace Cast\Database\Grammars;

use Cast\Database\Blueprint;
use Cast\Database\Column;

final class PostgresGrammar extends Grammar
{
    public function driver(): string
    {
        return 'pgsql';
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
            'integer' => 'INTEGER',
            'bigInteger' => 'BIGINT',
            'smallInteger', 'tinyInteger' => 'SMALLINT',
            'boolean' => 'BOOLEAN',
            'decimal' => 'NUMERIC(' . $a['precision'] . ', ' . $a['scale'] . ')',
            'float' => 'REAL',
            'double' => 'DOUBLE PRECISION',
            'date' => 'DATE',
            'time' => 'TIME(0) WITHOUT TIME ZONE',
            'dateTime', 'timestamp' => 'TIMESTAMP(0) WITHOUT TIME ZONE',
            'json' => 'JSONB',
            'uuid' => 'UUID',
            'binary' => 'BYTEA',
            'enum' => 'VARCHAR(255)',
        };
    }

    protected function incrementing(Column $c): string
    {
        return ($c->type === 'integer' ? 'SERIAL' : 'BIGSERIAL') . ' PRIMARY KEY';
    }

    protected function bool(bool $value): string
    {
        return $value ? 'TRUE' : 'FALSE';
    }

    protected function extra(Column $c): string
    {
        return $c->type === 'enum' ? $this->enumCheck($c) : '';
    }

    protected function afterCreate(Blueprint $bp): array
    {
        $out = [];
        foreach ($bp->columns as $c) {
            if ($c->comment !== null) $out[] = $this->commentOn($bp->table, $c);
        }
        return $out;
    }

    protected function afterAddColumn(Blueprint $bp, Column $c): array
    {
        return $c->comment !== null ? [$this->commentOn($bp->table, $c)] : [];
    }

    private function commentOn(string $table, Column $c): string
    {
        return 'COMMENT ON COLUMN ' . $this->id($table) . '.' . $this->id($c->name) . ' IS ' . $this->value($c->comment);
    }

    protected function dropIndex(string $table, string $name): string
    {
        return 'DROP INDEX ' . $this->id($name);
    }

    protected function dropForeignKey(string $table, string $name): string
    {
        return 'ALTER TABLE ' . $this->id($table) . ' DROP CONSTRAINT ' . $this->id($name);
    }

    protected function dropPrimary(string $table): string
    {
        return 'ALTER TABLE ' . $this->id($table) . ' DROP CONSTRAINT ' . $this->id($table . '_pkey');
    }

    public function compileHasTable(): string
    {
        return 'SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?';
    }

    public function compileListTables(): string
    {
        return 'SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() AND table_type = \'BASE TABLE\' ORDER BY table_name';
    }

    public function compileDropAll(array $tables): array
    {
        return $tables ? ['DROP TABLE ' . $this->ids($tables) . ' CASCADE'] : [];
    }

    public function compileListColumns(string $table): array
    {
        return ['SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? ORDER BY ordinal_position', [$table]];
    }
}

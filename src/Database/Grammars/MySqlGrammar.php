<?php

declare(strict_types=1);

namespace Cast\Database\Grammars;

use Cast\Database\Blueprint;
use Cast\Database\Column;

final class MySqlGrammar extends Grammar
{
    public function driver(): string
    {
        return 'mysql';
    }

    protected function quoteChar(): string
    {
        return '`';
    }

    protected function escape(string $value): string
    {
        // MySQL treats a backslash as an escape character by default
        return str_replace(['\\', "'"], ['\\\\', "''"], $value);
    }

    protected function type(Column $c): string
    {
        $a = $c->attributes;
        return match ($c->type) {
            'string' => 'VARCHAR(' . $a['length'] . ')',
            'char' => 'CHAR(' . $a['length'] . ')',
            'text' => 'TEXT',
            'mediumText' => 'MEDIUMTEXT',
            'longText' => 'LONGTEXT',
            'integer' => 'INT',
            'bigInteger' => 'BIGINT',
            'smallInteger' => 'SMALLINT',
            'tinyInteger' => 'TINYINT',
            'boolean' => 'TINYINT(1)',
            'decimal' => 'DECIMAL(' . $a['precision'] . ', ' . $a['scale'] . ')',
            'float' => 'FLOAT',
            'double' => 'DOUBLE',
            'date' => 'DATE',
            'time' => 'TIME',
            'dateTime' => 'DATETIME',
            'timestamp' => 'TIMESTAMP',
            'json' => 'JSON',
            'uuid' => 'CHAR(36)',
            'binary' => 'BLOB',
            'enum' => 'ENUM(' . implode(', ', array_map(fn($v) => $this->value($v), $a['values'])) . ')',
        };
    }

    protected function incrementing(Column $c): string
    {
        $type = $c->type === 'integer' ? 'INT' : 'BIGINT';
        return $type . ($c->unsigned ? ' UNSIGNED' : '') . ' NOT NULL AUTO_INCREMENT PRIMARY KEY';
    }

    protected function unsignedClause(Column $c): string
    {
        $numeric = ['integer', 'bigInteger', 'smallInteger', 'tinyInteger', 'decimal', 'float', 'double'];
        return $c->unsigned && in_array($c->type, $numeric, true) ? ' UNSIGNED' : '';
    }

    protected function bool(bool $value): string
    {
        return $value ? '1' : '0';
    }

    protected function commentClause(Column $c): string
    {
        return $c->comment !== null ? ' COMMENT ' . $this->value($c->comment) : '';
    }

    protected function afterClause(Column $c): string
    {
        return $c->after !== null ? ' AFTER ' . $this->id($c->after) : '';
    }

    protected function tableOptions(Blueprint $bp): string
    {
        $charset = $bp->charset ?? 'utf8mb4';
        $collation = $bp->collation ?? ($bp->charset === null || $bp->charset === 'utf8mb4' ? 'utf8mb4_unicode_ci' : $charset . '_general_ci');
        return " ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation";
    }

    protected function dropIndex(string $table, string $name): string
    {
        return 'DROP INDEX ' . $this->id($name) . ' ON ' . $this->id($table);
    }

    protected function dropForeignKey(string $table, string $name): string
    {
        return 'ALTER TABLE ' . $this->id($table) . ' DROP FOREIGN KEY ' . $this->id($name);
    }

    protected function dropPrimary(string $table): string
    {
        return 'ALTER TABLE ' . $this->id($table) . ' DROP PRIMARY KEY';
    }

    public function compileRename(string $from, string $to): string
    {
        return 'RENAME TABLE ' . $this->id($from) . ' TO ' . $this->id($to);
    }

    public function compileHasTable(): string
    {
        return 'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?';
    }

    public function compileListTables(): string
    {
        return 'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = \'BASE TABLE\' ORDER BY table_name';
    }

    public function compileDropAll(array $tables): array
    {
        if (!$tables) return [];
        return ['SET FOREIGN_KEY_CHECKS = 0', 'DROP TABLE ' . $this->ids($tables), 'SET FOREIGN_KEY_CHECKS = 1'];
    }

    public function compileListColumns(string $table): array
    {
        return ['SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position', [$table]];
    }
}

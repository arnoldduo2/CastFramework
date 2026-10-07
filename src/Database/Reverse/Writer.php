<?php

declare(strict_types=1);

namespace Cast\Database\Reverse;

use Cast\Database\Blueprint;

/** Turns table definitions from {@see Reader} into migration files, and orders tables so referenced tables come first. */
final class Writer
{
    /**
     * Tables ordered so that a table comes after the ones its foreign keys point to. Foreign keys that cannot be satisfied
     * (cycles) are moved out of the table and returned as `deferred`; a table that points to itself is fine.
     *
     * @param list<array<string, mixed>> $definitions
     * @return array{tables: list<array<string, mixed>>, deferred: array<string, list<array<string, mixed>>>}
     */
    public function order(array $definitions, bool $canAlterForeignKeys): array
    {
        $byName = [];
        foreach ($definitions as $d) $byName[$d['name']] = $d;
        $ordered = [];
        $done = [];
        $deferred = [];
        $visiting = [];

        $visit = function (string $name) use (&$visit, &$byName, &$ordered, &$done, &$deferred, &$visiting, $canAlterForeignKeys) {
            if (isset($done[$name])) return;
            $visiting[$name] = true;
            foreach ($byName[$name]['foreign'] as $i => $fk) {
                $target = $fk['table'];
                if ($target === $name || !isset($byName[$target])) continue;
                if (isset($visiting[$target])) {
                    // a cycle: this key is added afterwards (SQLite cannot, but it accepts forward references when creating)
                    if ($canAlterForeignKeys) {
                        $deferred[$name][] = $fk;
                        unset($byName[$name]['foreign'][$i]);
                    }
                    continue;
                }
                $visit($target);
            }
            unset($visiting[$name]);
            $byName[$name]['foreign'] = array_values($byName[$name]['foreign']);
            $done[$name] = true;
            $ordered[] = $byName[$name];
        };
        foreach (array_keys($byName) as $name) $visit($name);

        return ['tables' => $ordered, 'deferred' => $deferred];
    }

    /** The migration file for creating one table. @param array<string, mixed> $def */
    public function create(array $def, bool $autoIncrement = false): string
    {
        $table = $def['name'];
        $lines = [];
        foreach ($def['notes'] as $note) $lines[] = '// NOTE: ' . $note;

        if ($def['charset'] !== null && ($def['charset'] !== 'utf8mb4' || $def['collation'] !== 'utf8mb4_unicode_ci')) {
            $lines[] = '$table->charset(' . $this->str($def['charset']) . ');';
            if ($def['collation'] !== null) $lines[] = '$table->collation(' . $this->str($def['collation']) . ');';
        }
        $columns = $def['columns'];
        $autoColumns = array_column(array_filter($columns, fn($c) => $c['auto']), 'name');
        $primary = $def['primary'];
        if ($autoColumns && $primary === $autoColumns) $primary = [];
        $singlePrimary = count($primary) === 1 ? $primary[0] : null;

        $skip = [];
        $n = count($columns);
        for ($i = 0; $i < $n - 1; $i++) {
            if ($this->isTimestamp($columns[$i], 'created_at') && $this->isTimestamp($columns[$i + 1], 'updated_at')) {
                $skip[$columns[$i]['name']] = '$table->timestamps();';
                $skip[$columns[$i + 1]['name']] = '';
            }
        }
        foreach ($columns as $c) {
            if (isset($skip[$c['name']])) {
                if ($skip[$c['name']] !== '') $lines[] = $skip[$c['name']];
                continue;
            }
            $lines[] = $this->columnCode($c, $c['name'] === $singlePrimary);
        }
        if (count($primary) > 1) $lines[] = '$table->primary(' . $this->list($primary) . ');';

        foreach ($def['indexes'] as $index) {
            $type = $index['unique'] ? 'unique' : 'index';
            $named = $index['name'] !== null && $index['name'] !== Blueprint::indexName($table, $index['columns'], $type);
            $cols = count($index['columns']) === 1 ? $this->str($index['columns'][0]) : $this->list($index['columns']);
            $lines[] = "\$table->$type($cols" . ($named ? ', ' . $this->str($index['name']) : '') . ');';
        }
        foreach ($def['foreign'] as $fk) $lines[] = $this->foreignCode($table, $fk);

        $body = $this->indent(implode("\n", $lines), 3);
        $up = "        \$schema->create({$this->str($table)}, function (Blueprint \$table) {\n$body\n        });";
        if ($autoIncrement && $def['autoIncrement'] !== null && $def['autoIncrement'] > 1) {
            $up .= "\n        \$schema->autoIncrement({$this->str($table)}, {$def['autoIncrement']});";
        }
        return $this->file($up, "        \$schema->dropIfExists({$this->str($table)});");
    }

    /** A migration that adds foreign keys that could not be created together with their table. @param list<array<string, mixed>> $keys */
    public function foreignKeys(string $table, array $keys): string
    {
        $up = $this->indent(implode("\n", array_map(fn($fk) => $this->foreignCode($table, $fk), $keys)), 3);
        $down = $this->indent(implode("\n", array_map(fn($fk) => '$table->dropForeign(' . $this->str($this->fkName($table, $fk)) . ');', $keys)), 3);
        return $this->file(
            "        \$schema->table({$this->str($table)}, function (Blueprint \$table) {\n$up\n        });",
            "        \$schema->table({$this->str($table)}, function (Blueprint \$table) {\n$down\n        });",
        );
    }

    // ------------------------------------------------------------------ parts

    private function columnCode(array $c, bool $primary): string
    {
        $name = $this->str($c['name']);
        $type = $c['type'];

        if ($c['auto']) {
            if ($type === 'bigInteger' && $c['unsigned']) $code = "\$table->id($name)";
            elseif ($type === 'integer' && $c['unsigned']) $code = "\$table->increments($name)";
            else $code = "\$table->$type($name)" . ($c['unsigned'] ? '->unsigned()' : '') . '->autoIncrement()';
            return $code . $this->tail($c) . ';';
        }

        $args = match ($type) {
            'string', 'char' => $name . ((int) $c['length'] !== 255 ? ', ' . (int) $c['length'] : ''),
            'decimal' => $name . ((int) $c['precision'] !== 10 || (int) $c['scale'] !== 2 ? ', ' . (int) $c['precision'] . ', ' . (int) $c['scale'] : ''),
            'enum' => $name . ', ' . $this->list($c['values']),
            default => $name,
        };
        $code = "\$table->$type($args)";
        if ($c['unsigned'] && in_array($type, ['integer', 'bigInteger', 'smallInteger', 'tinyInteger', 'decimal', 'float', 'double'], true)) $code .= '->unsigned()';
        if ($c['nullable']) $code .= '->nullable()';
        $code .= $this->defaultCode($c);
        if ($c['collation'] !== null) $code .= '->collation(' . $this->str($c['collation']) . ')';
        if ($primary) $code .= '->primary()';
        return $code . $this->tail($c) . ';';
    }

    private function tail(array $c): string
    {
        return $c['comment'] !== null ? '->comment(' . $this->str($c['comment']) . ')' : '';
    }

    private function defaultCode(array $c): string
    {
        $d = $c['default'];
        if ($d === null) return '';
        if ($d['kind'] === 'current') {
            return in_array($c['type'], ['timestamp', 'dateTime'], true) ? '->useCurrent()' : "->default(Schema::raw('CURRENT_TIMESTAMP'))";
        }
        if ($d['kind'] === 'raw') return '->default(Schema::raw(' . $this->str((string) $d['value']) . '))';
        $value = $d['value'];
        if ($c['type'] === 'boolean') return '->default(' . ($value && $value !== '0' ? 'true' : 'false') . ')';
        return '->default(' . (is_string($value) ? $this->str($value) : var_export($value, true)) . ')';
    }

    private function foreignCode(string $table, array $fk): string
    {
        $cols = count($fk['columns']) === 1 ? $this->str($fk['columns'][0]) : $this->list($fk['columns']);
        $name = $fk['name'] !== null && $fk['name'] !== Blueprint::indexName($table, $fk['columns'], 'foreign') ? ', ' . $this->str($fk['name']) : '';
        $refs = count($fk['refColumns']) === 1 ? $this->str($fk['refColumns'][0]) : $this->list($fk['refColumns']);
        $code = "\$table->foreign($cols$name)->references($refs)->on({$this->str($fk['table'])})";
        foreach (['onDelete', 'onUpdate'] as $method) {
            $action = strtoupper((string) ($fk[$method] ?? ''));
            if ($action !== '' && $action !== 'NO ACTION' && $action !== 'RESTRICT') $code .= "->$method(" . $this->str(strtolower($action)) . ')';
        }
        return $code . ';';
    }

    private function fkName(string $table, array $fk): string
    {
        return $fk['name'] ?? Blueprint::indexName($table, $fk['columns'], 'foreign');
    }

    private function isTimestamp(array $c, string $name): bool
    {
        return $c['name'] === $name && $c['type'] === 'timestamp' && $c['nullable'] && $c['default'] === null && !$c['auto'];
    }

    private function file(string $up, string $down): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nuse Cast\\Database\\{Blueprint, Migration, Schema};\n\n"
            . "// Written by `php cast migrate:sync` from a table that already existed. Check it before relying on it.\n"
            . "return new class extends Migration {\n    public function up(Schema \$schema): void\n    {\n$up\n    }\n\n"
            . "    public function down(Schema \$schema): void\n    {\n$down\n    }\n};\n";
    }

    private function indent(string $code, int $levels): string
    {
        $pad = str_repeat('    ', $levels);
        return implode("\n", array_map(fn($l) => $l === '' ? '' : $pad . $l, explode("\n", $code)));
    }

    private function str(string $value): string
    {
        return "'" . addcslashes($value, "'\\") . "'";
    }

    /** @param list<string> $items */
    private function list(array $items): string
    {
        return '[' . implode(', ', array_map(fn($i) => $this->str((string) $i), $items)) . ']';
    }
}

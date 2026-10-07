<?php

declare(strict_types=1);

namespace Cast\Database\Reverse;

use Cast\Database\Column;
use Cast\Database\Grammars\Grammar;
use PDO;

/**
 * Tests the relationships of a whole database: every declared foreign key, and the `thing_id` columns that look like a
 * relationship but have no constraint. Results are `pass`, `warn` or `broken`.
 *
 * @phpstan-type Result array{status: string, kind: string, table: string, columns: list<string>, target: string, detail: string, orphans: int}
 */
final class Auditor
{
    public function __construct(private PDO $pdo, private Grammar $grammar) {}

    /**
     * @param list<array<string, mixed>> $definitions as read by {@see Reader}
     * @return list<array<string, mixed>>
     */
    public function run(array $definitions): array
    {
        $byName = [];
        foreach ($definitions as $d) $byName[$d['name']] = $d;
        $results = [];

        foreach ($definitions as $def) {
            $covered = [];
            foreach ($def['foreign'] as $fk) {
                $covered[implode(',', $fk['columns'])] = true;
                $results[] = $this->declared($def, $fk, $byName);
            }
            foreach ($def['columns'] as $col) {
                if (isset($covered[$col['name']]) || !preg_match('/^[a-z][a-z0-9_]*_id$/i', $col['name'])) continue;
                if ($def['primary'] === [$col['name']]) continue;
                $implied = $this->implied($def, $col, $byName);
                if ($implied !== null) $results[] = $implied;
            }
        }
        return $results;
    }

    /** @param array<string, mixed> $def @param array<string, mixed> $fk @param array<string, array<string, mixed>> $byName */
    private function declared(array $def, array $fk, array $byName): array
    {
        $row = ['kind' => 'foreign key', 'table' => $def['name'], 'columns' => $fk['columns'], 'target' => $fk['table'] . '(' . implode(', ', $fk['refColumns']) . ')', 'orphans' => 0];
        $parent = $byName[$fk['table']] ?? null;
        if ($parent === null) return $row + ['status' => 'broken', 'detail' => "the table \"{$fk['table']}\" does not exist"];

        $problems = [];
        $warnings = [];
        $parentColumns = array_column($parent['columns'], null, 'name');
        foreach ($fk['refColumns'] as $ref) {
            if (!isset($parentColumns[$ref])) $problems[] = "\"{$fk['table']}.$ref\" does not exist";
        }
        $childColumns = array_column($def['columns'], null, 'name');
        foreach ($fk['columns'] as $i => $name) {
            $a = $childColumns[$name] ?? null;
            $b = $parentColumns[$fk['refColumns'][$i] ?? ''] ?? null;
            if ($a && $b && !$this->compatible($a, $b)) {
                $warnings[] = "type differs: $name is {$this->typeOf($a)}, {$fk['table']}.{$b['name']} is {$this->typeOf($b)}";
            }
        }
        if (!$problems) {
            $orphans = $this->orphans($def['name'], $fk['columns'], $fk['table'], $fk['refColumns']);
            if ($orphans['count'] > 0) $problems[] = "{$orphans['count']} row" . ($orphans['count'] === 1 ? '' : 's') . ' point to nothing (e.g. ' . implode(', ', $orphans['sample']) . ')';
            $row['orphans'] = $orphans['count'];
            if (!$this->indexed($def, $fk['columns'])) $warnings[] = 'the column' . (count($fk['columns']) > 1 ? 's are' : ' is') . ' not indexed';
        }

        if ($problems) return $row + ['status' => 'broken', 'detail' => implode('; ', $problems)];
        if ($warnings) return $row + ['status' => 'warn', 'detail' => implode('; ', $warnings)];
        return $row + ['status' => 'pass', 'detail' => 'constraint holds, every row has its parent'];
    }

    /** A `customer_id` column with no constraint: where does it point, and does the data agree? */
    private function implied(array $def, array $col, array $byName): ?array
    {
        $target = Column::tableFor($col['name']);
        $base = (string) preg_replace('/_id$/i', '', $col['name']);
        foreach ([$target, $base, $base . 's'] as $candidate) {
            if (isset($byName[$candidate]) && $candidate !== $def['name']) {
                $target = $candidate;
                break;
            }
            $target = '';
        }
        if ($target === '') return null;

        $parent = $byName[$target];
        $ref = in_array('id', array_column($parent['columns'], 'name'), true) ? 'id' : ($parent['primary'][0] ?? null);
        if ($ref === null || count($parent['primary']) > 1) return null;

        $row = ['kind' => 'no constraint', 'table' => $def['name'], 'columns' => [$col['name']], 'target' => "$target($ref)", 'orphans' => 0];
        $orphans = $this->orphans($def['name'], [$col['name']], $target, [$ref]);
        $row['orphans'] = $orphans['count'];
        $refCol = array_column($parent['columns'], null, 'name')[$ref];
        $notes = [];
        if (!$this->compatible($col, $refCol)) {
            $notes[] = "a constraint is not possible until the types match ({$this->typeOf($col)} vs {$this->typeOf($refCol)})";
        }
        if ($orphans['count'] > 0) {
            return $row + ['status' => 'broken', 'detail' => "{$orphans['count']} row" . ($orphans['count'] === 1 ? '' : 's') . ' point to nothing (e.g. ' . implode(', ', $orphans['sample']) . ')' . ($notes ? '; ' . implode('; ', $notes) : '')];
        }
        return $row + ['status' => 'warn', 'detail' => 'looks like a relationship but has no foreign key; the data is consistent' . ($notes ? '; ' . implode('; ', $notes) : '')];
    }

    /** @return array{count: int, sample: list<string>} rows of $child whose key has no row in $parent */
    private function orphans(string $child, array $columns, string $parent, array $refColumns): array
    {
        $g = $this->grammar;
        $on = [];
        $notNull = [];
        foreach ($columns as $i => $c) {
            $on[] = 'c.' . $g->id($c) . ' = p.' . $g->id($refColumns[$i]);
            $notNull[] = 'c.' . $g->id($c) . ' IS NOT NULL';
        }
        $from = ' FROM ' . $g->id($child) . ' c LEFT JOIN ' . $g->id($parent) . ' p ON ' . implode(' AND ', $on)
            . ' WHERE ' . implode(' AND ', $notNull) . ' AND p.' . $g->id($refColumns[0]) . ' IS NULL';
        try {
            $count = (int) $this->pdo->query('SELECT COUNT(*)' . $from)->fetchColumn();
            $sample = [];
            if ($count > 0) {
                $select = implode(', ', array_map(fn($c) => 'c.' . $g->id($c), $columns));
                $stmt = $this->pdo->query("SELECT DISTINCT $select$from LIMIT 5");
                foreach ($stmt->fetchAll(PDO::FETCH_NUM) as $values) $sample[] = implode('/', array_map('strval', $values));
            }
            return ['count' => $count, 'sample' => $sample];
        } catch (\PDOException) {
            return ['count' => 0, 'sample' => []];   // the types cannot be compared on this database: reported through the type warning
        }
    }

    private function indexed(array $def, array $columns): bool
    {
        if ($def['primary'] && array_slice($def['primary'], 0, count($columns)) === $columns) return true;
        foreach ($def['indexes'] as $index) {
            if (array_slice($index['columns'], 0, count($columns)) === $columns) return true;
        }
        return false;
    }

    /** Can a foreign key join these two columns? (SQLite has a single integer type, so every integer matches.) */
    private function compatible(array $a, array $b): bool
    {
        $ints = ['integer', 'bigInteger', 'smallInteger', 'tinyInteger'];
        if ($this->grammar->driver() === 'sqlite' && in_array($a['type'], $ints, true) && in_array($b['type'], $ints, true)) return true;
        return $a['type'] === $b['type'] && $a['unsigned'] === $b['unsigned'];
    }

    private function typeOf(array $c): string
    {
        return ($c['unsigned'] ? 'unsigned ' : '') . $c['type'];
    }
}

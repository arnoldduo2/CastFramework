<?php

declare(strict_types=1);

namespace Cast\Database\Reverse;

use Cast\Database\Grammars\Grammar;
use Cast\Database\MigrationException;
use Cast\Database\Schema;
use PDO;

/**
 * Reads the structure of existing tables (MySQL/MariaDB, PostgreSQL, SQLite) into plain arrays that {@see Writer} turns into migrations.
 *
 * A table is
 *   ['name', 'columns' => [column...], 'primary' => [names], 'indexes' => [['name', 'columns', 'unique']],
 *    'foreign' => [['name', 'columns', 'table', 'refColumns', 'onDelete', 'onUpdate']], 'notes' => [text...]]
 * and a column
 *   ['name', 'type', 'length', 'precision', 'scale', 'values', 'unsigned', 'nullable', 'auto', 'default', 'comment', 'native']
 * where 'type' is a Blueprint type (string, integer, decimal, ...) and 'default' is null or ['kind' => literal|current|raw, 'value'].
 */
final class Reader
{
    private string $driver;
    private Schema $schema;

    public function __construct(private PDO $pdo, private Grammar $grammar)
    {
        $this->driver = $grammar->driver();
        $this->schema = new Schema($pdo, $grammar);
    }

    /** @return list<string> */
    public function tables(): array
    {
        $names = array_map('strval', $this->pdo->query($this->grammar->compileListTables())->fetchAll(PDO::FETCH_COLUMN));
        return array_values(array_filter($names, fn($t) => !str_starts_with($t, 'sqlite_')));
    }

    /** @return array<string, mixed> */
    public function read(string $table): array
    {
        $def = match ($this->driver) {
            'mysql' => $this->mysql($table),
            'pgsql' => $this->pgsql($table),
            'sqlite' => $this->sqlite($table),
            default => throw new MigrationException("Reading tables is not supported for the \"{$this->driver}\" driver."),
        };
        if (!$def['columns']) throw new MigrationException("Table \"$table\" does not exist or has no columns.");
        $def['autoIncrement'] = $this->schema->autoIncrementColumn($table) !== null ? $this->schema->nextAutoIncrement($table) : null;
        return $def;
    }

    // ------------------------------------------------------------------ MySQL

    private function mysql(string $table): array
    {
        $rows = $this->all(
            'SELECT column_name AS name, data_type AS data_type, column_type AS column_type, character_maximum_length AS len, numeric_precision AS prec,
                    numeric_scale AS scale, is_nullable AS nullable, column_default AS dflt, extra AS extra, column_comment AS comment, collation_name AS coll
             FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position',
            [$table],
        );
        $columns = [];
        $notes = [];
        $primary = [];
        foreach ($rows as $r) {
            $mapped = $this->map((string) $r['data_type'], $r['len'] !== null ? (int) $r['len'] : null, $r['prec'] !== null ? (int) $r['prec'] : null, $r['scale'] !== null ? (int) $r['scale'] : null, (string) $r['column_type']);
            $extra = strtolower((string) $r['extra']);
            $default = $this->mysqlDefault($r['dflt'], $extra, $mapped['type']);
            if (str_contains($extra, 'on update')) $notes[] = "column \"{$r['name']}\" has ON UPDATE in the database; it is not part of the migration";
            if (str_contains($extra, 'generated') && !str_contains($extra, 'default_generated')) $notes[] = "column \"{$r['name']}\" is a generated column; it is created as a plain column";
            $columns[] = $this->column($r['name'], $mapped, strtoupper((string) $r['nullable']) === 'YES', str_contains($extra, 'auto_increment'), $default, (string) $r['comment'], $r['coll'] !== null ? (string) $r['coll'] : null);
        }

        $indexes = [];
        foreach ($this->all('SELECT index_name AS name, non_unique AS non_unique, column_name AS col FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? ORDER BY index_name, seq_in_index', [$table]) as $r) {
            if ($r['col'] === null) {
                $notes[] = "index \"{$r['name']}\" is an expression index and is left out";
                continue;
            }
            $key = $r['name'];
            if ($key === 'PRIMARY') {
                $primary[] = $r['col'];
                continue;
            }
            $indexes[$key]['name'] = $key;
            $indexes[$key]['unique'] = (int) $r['non_unique'] === 0;
            $indexes[$key]['columns'][] = $r['col'];
        }

        $foreign = [];
        foreach ($this->all(
            'SELECT k.constraint_name AS name, k.column_name AS col, k.referenced_table_name AS ref_table, k.referenced_column_name AS ref_col,
                    r.delete_rule AS on_delete, r.update_rule AS on_update
             FROM information_schema.key_column_usage k
             JOIN information_schema.referential_constraints r ON r.constraint_schema = k.constraint_schema AND r.constraint_name = k.constraint_name AND r.table_name = k.table_name
             WHERE k.table_schema = DATABASE() AND k.table_name = ? AND k.referenced_table_name IS NOT NULL
             ORDER BY k.constraint_name, k.ordinal_position',
            [$table],
        ) as $r) {
            $foreign[$r['name']]['name'] = $r['name'];
            $foreign[$r['name']]['table'] = $r['ref_table'];
            $foreign[$r['name']]['columns'][] = $r['col'];
            $foreign[$r['name']]['refColumns'][] = $r['ref_col'];
            $foreign[$r['name']]['onDelete'] = $r['on_delete'];
            $foreign[$r['name']]['onUpdate'] = $r['on_update'];
        }

        $tableColl = $this->all('SELECT table_collation AS coll FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table])[0]['coll'] ?? null;
        if ($tableColl !== null) {
            // a column only carries its collation when it differs from the table's
            foreach ($columns as &$c) {
                if ($c['collation'] === $tableColl) $c['collation'] = null;
            }
            unset($c);
        }
        $def = $this->table($table, $columns, $primary, $indexes, $foreign, $notes);
        $def['collation'] = $tableColl;
        $def['charset'] = $tableColl !== null ? strtok((string) $tableColl, '_') : null;
        return $def;
    }

    private function mysqlDefault(mixed $value, string $extra, string $type): ?array
    {
        if ($value === null) return null;
        $value = (string) $value;
        if (str_contains($extra, 'default_generated')) {
            return preg_match('/^(current_timestamp|now)\b(\(\d*\))?$/i', $value) ? ['kind' => 'current', 'value' => null] : ['kind' => 'raw', 'value' => $value];
        }
        // MariaDB reports string defaults quoted and "NULL" as text
        if (strcasecmp($value, 'NULL') === 0) return null;
        if (preg_match("/^'(.*)'$/s", $value, $m)) return ['kind' => 'literal', 'value' => str_replace("''", "'", $m[1])];
        if (preg_match('/^(current_timestamp|now)\b(\(\d*\))?$/i', $value)) return ['kind' => 'current', 'value' => null];
        if (is_numeric($value) && !in_array($type, ['string', 'char', 'text', 'mediumText', 'longText', 'date', 'time', 'dateTime', 'timestamp', 'uuid', 'enum', 'json'], true)) {
            return ['kind' => 'literal', 'value' => $this->number($value)];
        }
        return ['kind' => 'literal', 'value' => $value];
    }

    // ------------------------------------------------------------- PostgreSQL

    private function pgsql(string $table): array
    {
        $rows = $this->all(
            "SELECT column_name AS name, data_type AS data_type, udt_name AS udt, character_maximum_length AS len, numeric_precision AS prec,
                    numeric_scale AS scale, is_nullable AS nullable, column_default AS dflt, is_identity AS ident, collation_name AS coll
             FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? ORDER BY ordinal_position",
            [$table],
        );
        $columns = [];
        $notes = [];
        foreach ($rows as $r) {
            $native = (string) $r['data_type'] === 'USER-DEFINED' ? (string) $r['udt'] : (string) $r['data_type'];
            $mapped = $this->map($native, $r['len'] !== null ? (int) $r['len'] : null, $r['prec'] !== null ? (int) $r['prec'] : null, $r['scale'] !== null ? (int) $r['scale'] : null, $native);
            $dflt = $r['dflt'] !== null ? (string) $r['dflt'] : null;
            $auto = strtoupper((string) $r['ident']) === 'YES' || ($dflt !== null && stripos($dflt, 'nextval(') === 0);
            $default = $auto ? null : $this->pgDefault($dflt);
            if ($native === 'USER-DEFINED' || (!$mapped['known'] && $native !== '')) {
                $notes[] = "column \"{$r['name']}\" is of type \"$native\"; it is written as text";
            }
            $columns[] = $this->column($r['name'], $mapped, strtoupper((string) $r['nullable']) === 'YES', $auto, $default, '', $r['coll'] !== null && $r['coll'] !== 'default' ? (string) $r['coll'] : null);
        }

        $primary = [];
        $indexes = [];
        foreach ($this->all(
            "SELECT i.relname AS name, ix.indisunique AS uniq, ix.indisprimary AS pri, a.attname AS col, ix.indnatts AS natts
             FROM pg_class t JOIN pg_index ix ON t.oid = ix.indrelid JOIN pg_class i ON i.oid = ix.indexrelid
             JOIN pg_namespace n ON n.oid = t.relnamespace
             JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY (ix.indkey::int2[])
             WHERE t.relname = ? AND n.nspname = current_schema() AND ix.indpred IS NULL
             ORDER BY i.relname, array_position(ix.indkey::int2[], a.attnum)",
            [$table],
        ) as $r) {
            if ($r['pri']) {
                $primary[] = $r['col'];
                continue;
            }
            $indexes[$r['name']]['name'] = $r['name'];
            $indexes[$r['name']]['unique'] = (bool) $r['uniq'];
            $indexes[$r['name']]['columns'][] = $r['col'];
            $indexes[$r['name']]['natts'] = (int) $r['natts'];
        }
        foreach ($indexes as $name => $index) {
            if ($index['natts'] !== count($index['columns'])) {
                $notes[] = "index \"$name\" is an expression index and is left out";
                unset($indexes[$name]);
            }
        }

        $actions = ['a' => 'NO ACTION', 'r' => 'RESTRICT', 'c' => 'CASCADE', 'n' => 'SET NULL', 'd' => 'SET DEFAULT'];
        $foreign = [];
        foreach ($this->all(
            "SELECT c.conname AS name, c.confdeltype AS del, c.confupdtype AS upd, ft.relname AS ref_table,
                    (SELECT string_agg(a.attname, ',' ORDER BY k.ord) FROM unnest(c.conkey) WITH ORDINALITY k(attnum, ord) JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = k.attnum) AS cols,
                    (SELECT string_agg(a.attname, ',' ORDER BY k.ord) FROM unnest(c.confkey) WITH ORDINALITY k(attnum, ord) JOIN pg_attribute a ON a.attrelid = c.confrelid AND a.attnum = k.attnum) AS ref_cols
             FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid JOIN pg_namespace n ON n.oid = t.relnamespace JOIN pg_class ft ON ft.oid = c.confrelid
             WHERE c.contype = 'f' AND t.relname = ? AND n.nspname = current_schema() ORDER BY c.conname",
            [$table],
        ) as $r) {
            $foreign[$r['name']] = [
                'name' => $r['name'], 'table' => $r['ref_table'], 'columns' => explode(',', (string) $r['cols']), 'refColumns' => explode(',', (string) $r['ref_cols']),
                'onDelete' => $actions[$r['del']] ?? 'NO ACTION', 'onUpdate' => $actions[$r['upd']] ?? 'NO ACTION',
            ];
        }

        return $this->table($table, $columns, $primary, $indexes, $foreign, $notes);
    }

    private function pgDefault(?string $value): ?array
    {
        if ($value === null) return null;
        $value = trim($value);
        if (preg_match('/^NULL(::[\w\s"\[\]]+)?$/i', $value)) return null;
        if (preg_match('/^(current_timestamp|now\(\)|localtimestamp|current_date|current_time)\b/i', $value) && !preg_match('/\+|-/', $value)) {
            return ['kind' => 'current', 'value' => null];
        }
        if (preg_match("/^'((?:[^']|'')*)'(::[\\w\\s\"\\[\\]]+)?$/s", $value, $m)) return ['kind' => 'literal', 'value' => str_replace("''", "'", $m[1])];
        if (preg_match('/^\(?(-?\d+(?:\.\d+)?)\)?(::[\w\s]+)?$/', $value, $m)) return ['kind' => 'literal', 'value' => $this->number($m[1])];
        if (strtolower($value) === 'true' || strtolower($value) === 'false') return ['kind' => 'literal', 'value' => strtolower($value) === 'true'];
        return ['kind' => 'raw', 'value' => $value];
    }

    // ----------------------------------------------------------------- SQLite

    private function sqlite(string $table): array
    {
        $quoted = '"' . str_replace('"', '""', $table) . '"';
        $info = $this->all("PRAGMA table_info($quoted)");
        $columns = [];
        $notes = [];
        $pk = [];
        foreach ($info as $r) {
            if ((int) $r['pk'] > 0) $pk[(int) $r['pk']] = $r['name'];
        }
        ksort($pk);
        foreach ($info as $r) {
            $declared = strtolower(trim((string) $r['type']));
            preg_match('/^([a-z ]*?)\s*(?:\(\s*(\d+)\s*(?:,\s*(\d+)\s*)?\))?$/', $declared, $m);
            $base = trim($m[1] ?? $declared);
            $mapped = $this->map($base, isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null, isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null, isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null, $declared);
            $auto = count($pk) === 1 && $pk[array_key_first($pk)] === $r['name'] && $base === 'integer';
            if ($auto) $mapped = ['type' => 'bigInteger', 'length' => null, 'precision' => null, 'scale' => null, 'values' => null, 'unsigned' => true, 'known' => true, 'note' => null];
            $columns[] = $this->column($r['name'], $mapped, (int) $r['notnull'] === 0 && !$auto, $auto, $this->sqliteDefault($r['dflt_value']), '');
        }

        $indexes = [];
        foreach ($this->all("PRAGMA index_list($quoted)") as $idx) {
            if ($idx['origin'] === 'pk') continue;
            $cols = array_column($this->all('PRAGMA index_info("' . str_replace('"', '""', $idx['name']) . '")'), 'name');
            if (in_array(null, $cols, true)) {
                $notes[] = "index \"{$idx['name']}\" is an expression index and is left out";
                continue;
            }
            $auto = str_starts_with($idx['name'], 'sqlite_autoindex_');
            $indexes[$idx['name']] = ['name' => $auto ? null : $idx['name'], 'unique' => (int) $idx['unique'] === 1, 'columns' => $cols];
        }

        $foreign = [];
        foreach ($this->all("PRAGMA foreign_key_list($quoted)") as $r) {
            $key = $r['id'];
            $foreign[$key]['name'] = null;
            $foreign[$key]['table'] = $r['table'];
            $foreign[$key]['columns'][] = $r['from'];
            $foreign[$key]['refColumns'][] = $r['to'];
            $foreign[$key]['onDelete'] = $r['on_delete'];
            $foreign[$key]['onUpdate'] = $r['on_update'];
        }
        foreach ($foreign as &$fk) {
            // `REFERENCES t` without a column means t's primary key
            $fk['refColumns'] = array_map(fn($c) => $c ?? 'id', $fk['refColumns']);
        }
        unset($fk);

        $primary = count($pk) === 1 && $columns && $this->hasAuto($columns) ? [] : array_values($pk);
        return $this->table($table, $columns, $primary, $indexes, $foreign, $notes);
    }

    private function sqliteDefault(mixed $value): ?array
    {
        if ($value === null) return null;
        $value = trim((string) $value);
        if (strcasecmp($value, 'NULL') === 0) return null;
        if (preg_match('/^(current_timestamp|current_date|current_time)$/i', $value)) return ['kind' => 'current', 'value' => null];
        if (preg_match("/^'((?:[^']|'')*)'$/s", $value, $m)) return ['kind' => 'literal', 'value' => str_replace("''", "'", $m[1])];
        if (is_numeric($value)) return ['kind' => 'literal', 'value' => $this->number($value)];
        return ['kind' => 'raw', 'value' => $value];
    }

    // ----------------------------------------------------------------- shared

    /**
     * The Blueprint type for a native database type.
     * @return array{type: string, length: ?int, precision: ?int, scale: ?int, values: ?array, unsigned: bool, known: bool, note: ?string}
     */
    private function map(string $native, ?int $length, ?int $precision, ?int $scale, string $columnType): array
    {
        $native = strtolower(trim($native));
        $unsigned = str_contains(strtolower($columnType), 'unsigned');
        $out = ['type' => 'text', 'length' => null, 'precision' => null, 'scale' => null, 'values' => null, 'unsigned' => $unsigned, 'known' => true, 'note' => null];
        $set = function (string $type, ?string $note = null) use (&$out) {
            $out['type'] = $type;
            $out['note'] = $note;
        };

        switch ($native) {
            case 'tinyint':
                // MySQL's boolean is tinyint(1)
                if (preg_match('/^tinyint\(1\)/i', $columnType)) {
                    $set('boolean');
                    $out['unsigned'] = false;
                } else {
                    $set('tinyInteger');
                }
                break;
            case 'smallint': case 'int2': case 'smallserial': $set('smallInteger'); break;
            case 'mediumint': $set('integer', 'MEDIUMINT is written as INT'); break;
            case 'int': case 'integer': case 'int4': case 'serial': $set('integer'); break;
            case 'bigint': case 'int8': case 'bigserial': $set('bigInteger'); break;
            case 'year': $set('smallInteger', 'YEAR is written as SMALLINT'); break;
            case 'bool': case 'boolean': $set('boolean'); break;
            case 'varchar': case 'character varying': case 'nvarchar': case 'varying character': case 'nchar varying':
                if ($length === null) {
                    $set('text');
                } else {
                    $set('string');
                    $out['length'] = $length;
                }
                break;
            case 'char': case 'character': case 'bpchar': case 'nchar': case 'native character':
                $set('char');
                $out['length'] = $length ?? 1;
                break;
            case 'text': case 'tinytext': case 'clob': case 'citext': $set('text'); break;
            case 'mediumtext': $set('mediumText'); break;
            case 'longtext': $set('longText'); break;
            case 'decimal': case 'numeric': case 'dec': case 'money':
                $set('decimal', $precision === null ? 'DECIMAL without a size is written as DECIMAL(38, 10)' : null);
                $out['precision'] = $precision ?? 38;
                $out['scale'] = $scale ?? ($precision === null ? 10 : 0);
                break;
            case 'float': case 'real': case 'float4': $set('float'); break;
            case 'double': case 'double precision': case 'float8': $set('double'); break;
            case 'date': $set('date'); break;
            case 'time': case 'time without time zone': case 'timetz': case 'time with time zone': $set('time'); break;
            case 'datetime': $set('dateTime'); break;
            case 'timestamp': case 'timestamp without time zone': $set('timestamp'); break;
            case 'timestamp with time zone': case 'timestamptz': $set('timestamp', 'TIMESTAMP WITH TIME ZONE is written as TIMESTAMP'); break;
            case 'json': $set('json'); break;
            case 'jsonb': $set('json', 'JSONB is written as JSON'); break;
            case 'uuid': $set('uuid'); break;
            case 'blob': case 'tinyblob': case 'mediumblob': case 'longblob': case 'binary': case 'varbinary': case 'bytea':
                $set('binary');
                break;
            case 'enum':
                $set('enum');
                preg_match_all("/'((?:[^']|'')*)'/", $columnType, $m);
                $out['values'] = array_map(fn($v) => str_replace("''", "'", $v), $m[1]);
                if (!$out['values']) $set('string');
                break;
            case '':
                $set('text', 'a column without a type is written as text');
                $out['known'] = false;
                break;
            default:
                $set('text', "type \"$native\" has no equivalent here and is written as text");
                $out['known'] = false;
        }
        if ($out['type'] === 'string' && $out['length'] === null) $out['length'] = 255;
        return $out;
    }

    /** @param array<string, mixed> $mapped */
    private function column(string $name, array $mapped, bool $nullable, bool $auto, ?array $default, string $comment, ?string $collation = null): array
    {
        return [
            'name' => $name, 'type' => $mapped['type'], 'length' => $mapped['length'], 'precision' => $mapped['precision'], 'scale' => $mapped['scale'],
            'values' => $mapped['values'], 'unsigned' => $mapped['unsigned'], 'nullable' => $nullable, 'auto' => $auto, 'default' => $default,
            'comment' => $comment !== '' ? $comment : null, 'collation' => $collation, 'note' => $mapped['note'],
        ];
    }

    private function table(string $name, array $columns, array $primary, array $indexes, array $foreign, array $notes): array
    {
        foreach ($columns as $c) {
            if ($c['note'] !== null) $notes[] = "column \"{$c['name']}\": {$c['note']}";
        }
        return ['name' => $name, 'collation' => null, 'charset' => null, 'autoIncrement' => null, 'columns' => $columns, 'primary' => $primary, 'indexes' => array_values($indexes), 'foreign' => array_values($foreign), 'notes' => array_values(array_unique($notes))];
    }

    private function hasAuto(array $columns): bool
    {
        foreach ($columns as $c) {
            if ($c['auto']) return true;
        }
        return false;
    }

    private function number(string $value): int|float
    {
        return preg_match('/^-?\d+$/', $value) ? (int) $value : (float) $value;
    }

    /** @return list<array<string, mixed>> */
    private function all(string $sql, array $bindings = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

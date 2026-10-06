<?php

declare(strict_types=1);

namespace Cast\Core;

use InvalidArgumentException;
use PDO;

/**
 * Fluent, parameter-binding query builder.
 *
 * Every value is bound as a unique named PDO parameter and every identifier (table, column, sort
 * direction, operator) is validated against an allowlist before it is placed in the SQL string.
 *
 *   QueryBuilder::table('users')->where('active', 1)->and('role', '!=', 'admin')->orderBy('name')->get();
 */
class QueryBuilder
{
    private string $table;
    private array $selectColumns = ['*'];
    /** @var array<int, array{boolean: string, sql: string}> */
    private array $whereClauses = [];
    /** @var array<string, mixed> */
    private array $bindings = [];
    private int $paramCounter = 0;
    /** @var list<array{string, string}> */
    private array $orders = [];
    private ?int $limitCount = null;
    private int $limitOffset = 0;

    private const IDENTIFIER_PATTERN = '/^[a-zA-Z0-9_\.]+$/';
    private const ALLOWED_OPERATORS = ['=', '!=', '<>', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE', 'IS', 'IS NOT', 'RLIKE', 'NOT RLIKE'];
    private const ALLOWED_DIRECTIONS = ['ASC', 'DESC'];

    public function __construct(string $table)
    {
        $this->assertIdentifier($table, 'table');
        $this->table = $table;
    }

    public static function table(string $table): static
    {
        return new static($table);
    }

    protected static function conn(): PDO
    {
        return Database::connection();
    }

    public static function beginTrans(): bool
    {
        return self::conn()->beginTransaction();
    }

    public static function commitTrans(): bool
    {
        return self::conn()->commit();
    }

    public static function rollbackTrans(): bool
    {
        return self::conn()->rollBack();
    }

    public static function inTransaction(): bool
    {
        return self::conn()->inTransaction();
    }

    private function assertIdentifier(string $value, string $label): void
    {
        if (!preg_match(self::IDENTIFIER_PATTERN, $value)) {
            throw new InvalidArgumentException("Invalid $label identifier: \"$value\"");
        }
    }

    private function nextParam(string $column): string
    {
        return ':p_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $column) . '_' . (++$this->paramCounter);
    }

    public function select(array $columns = ['*']): static
    {
        foreach ($columns as $col) {
            if ($col === '*') continue;
            $this->assertIdentifier((string) $col, 'column');
        }
        $this->selectColumns = $columns ?: ['*'];
        return $this;
    }

    private function addCondition(string $boolean, string $column, string $operator, mixed $value): static
    {
        $this->assertIdentifier($column, 'column');
        $operator = strtoupper($operator);
        if (!in_array($operator, self::ALLOWED_OPERATORS, true)) {
            throw new InvalidArgumentException("Invalid operator: \"$operator\"");
        }

        if ($value === null) {
            $sql = in_array($operator, ['!=', '<>', 'IS NOT'], true) ? "$column IS NOT NULL" : "$column IS NULL";
            $this->whereClauses[] = ['boolean' => $boolean, 'sql' => $sql];
            return $this;
        }

        $param = $this->nextParam($column);
        $this->whereClauses[] = ['boolean' => $boolean, 'sql' => "$column $operator $param"];
        $this->bindings[$param] = $value;
        return $this;
    }

    /** `where('active', 1)` or `where('active', '!=', 1)`. */
    public function where(string $column, mixed $operatorOrValue, mixed $value = null): static
    {
        return func_num_args() === 2
            ? $this->addCondition('AND', $column, '=', $operatorOrValue)
            : $this->addCondition('AND', $column, (string) $operatorOrValue, $value);
    }

    public function and(string $column, mixed $operatorOrValue, mixed $value = null): static
    {
        return func_num_args() === 2
            ? $this->addCondition('AND', $column, '=', $operatorOrValue)
            : $this->addCondition('AND', $column, (string) $operatorOrValue, $value);
    }

    public function or(string $column, mixed $operatorOrValue, mixed $value = null): static
    {
        return func_num_args() === 2
            ? $this->addCondition('OR', $column, '=', $operatorOrValue)
            : $this->addCondition('OR', $column, (string) $operatorOrValue, $value);
    }

    public function whereIn(string $column, array $values): static
    {
        $this->assertIdentifier($column, 'column');

        if (empty($values)) {
            $this->whereClauses[] = ['boolean' => 'AND', 'sql' => '1 = 0'];
            return $this;
        }

        $placeholders = [];
        foreach (array_values($values) as $value) {
            $param = $this->nextParam($column);
            $placeholders[] = $param;
            $this->bindings[$param] = $value;
        }

        $this->whereClauses[] = ['boolean' => 'AND', 'sql' => "$column IN (" . implode(', ', $placeholders) . ')'];
        return $this;
    }

    /** Can be called more than once to sort by several columns. */
    public function orderBy(string $column, string $direction = 'ASC'): static
    {
        $this->assertIdentifier($column, 'column');
        $direction = strtoupper($direction);
        if (!in_array($direction, self::ALLOWED_DIRECTIONS, true)) {
            throw new InvalidArgumentException("Invalid sort direction: \"$direction\"");
        }
        $this->orders[] = [$column, $direction];
        return $this;
    }

    public function limit(int $limit, int $offset = 0): static
    {
        if ($limit < 0 || $offset < 0) {
            throw new InvalidArgumentException('limit/offset must be non-negative');
        }
        $this->limitCount = $limit;
        $this->limitOffset = $offset;
        return $this;
    }

    private function buildWhereSql(): string
    {
        if (empty($this->whereClauses)) return '';
        $sql = 'WHERE ';
        foreach ($this->whereClauses as $i => $clause) {
            $sql .= $i === 0 ? $clause['sql'] : " {$clause['boolean']} {$clause['sql']}";
        }
        return $sql;
    }

    private function buildSelect(string $columns): string
    {
        $sql = "SELECT $columns FROM {$this->table} " . $this->buildWhereSql();
        if ($this->orders) {
            $sql .= ' ORDER BY ' . implode(', ', array_map(fn($o) => "$o[0] $o[1]", $this->orders));
        }
        if ($this->limitCount !== null) {
            $sql .= " LIMIT {$this->limitCount} OFFSET {$this->limitOffset}";
        }
        return trim($sql);
    }

    /** Runs the SELECT and returns it as 'fetchAll' (default), 'fetch', or 'rowCount'. */
    public function executeSafe(?string $fetchMode = 'fetchAll'): bool|array|int
    {
        $stmt = self::conn()->prepare($this->buildSelect(implode(', ', $this->selectColumns)));
        $stmt->execute($this->bindings);

        return match ($fetchMode) {
            'fetch' => $stmt->fetch(PDO::FETCH_ASSOC) ?: [],
            'rowCount' => $stmt->rowCount(),
            default => $stmt->fetchAll(PDO::FETCH_ASSOC),
        };
    }

    /** True when at least one row matches (works on tables without an `id` column). */
    public function exists(): bool
    {
        $stmt = self::conn()->prepare('SELECT 1 FROM ' . $this->table . ' ' . $this->buildWhereSql() . ' LIMIT 1');
        $stmt->execute($this->bindings);
        return $stmt->fetchColumn() !== false;
    }

    public function count(): int
    {
        $stmt = self::conn()->prepare('SELECT COUNT(*) FROM ' . $this->table . ' ' . $this->buildWhereSql());
        $stmt->execute($this->bindings);
        return (int) $stmt->fetchColumn();
    }

    /** The first matching row, or false. */
    public function first(): array|false
    {
        $res = $this->limit(1)->executeSafe('fetch');
        return (!empty($res) && is_array($res)) ? $res : false;
    }

    public function fetchAll(): array
    {
        $res = $this->executeSafe('fetchAll');
        return is_array($res) ? $res : [];
    }

    public function fetch(): array|false
    {
        $res = $this->executeSafe('fetch');
        return (!empty($res) && is_array($res)) ? $res : false;
    }

    /** Alias for fetchAll(). */
    public function get(): array
    {
        return $this->fetchAll();
    }

    /** Alias for executeSafe(). */
    public function execute(?string $fetchMode = 'fetchAll'): bool|array|int
    {
        return $this->executeSafe($fetchMode);
    }

    /** @return int The new row's auto-increment id. */
    public function insert(array $data): int
    {
        if (empty($data)) {
            throw new InvalidArgumentException('insert() requires at least one column');
        }

        $columns = [];
        $placeholders = [];
        $bindings = [];
        foreach ($data as $column => $value) {
            $this->assertIdentifier((string) $column, 'column');
            $param = $this->nextParam((string) $column);
            $columns[] = $column;
            $placeholders[] = $param;
            $bindings[$param] = $value;
        }

        $stmt = self::conn()->prepare("INSERT INTO {$this->table} (" . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')');
        $stmt->execute($bindings);

        return (int) self::conn()->lastInsertId();
    }

    /** @return int Number of affected rows. */
    public function update(array $data): int
    {
        if (empty($data)) {
            throw new InvalidArgumentException('update() requires at least one column');
        }

        $sets = [];
        $bindings = [];
        foreach ($data as $column => $value) {
            $this->assertIdentifier((string) $column, 'column');
            $param = $this->nextParam($column . '_set');
            $sets[] = "$column = $param";
            $bindings[$param] = $value;
        }

        $stmt = self::conn()->prepare(trim("UPDATE {$this->table} SET " . implode(', ', $sets) . ' ' . $this->buildWhereSql()));
        $stmt->execute(array_merge($bindings, $this->bindings));

        return $stmt->rowCount();
    }

    /** @return int Number of affected rows. */
    public function delete(): int
    {
        $stmt = self::conn()->prepare(trim("DELETE FROM {$this->table} " . $this->buildWhereSql()));
        $stmt->execute($this->bindings);

        return $stmt->rowCount();
    }

    /**
     * Escape hatch for queries the builder can't express; values must still be bound.
     *
     *   QueryBuilder::executeSql('SELECT * FROM t WHERE a = :a', ['a' => 1], 'fetchAll');
     *
     * @param array<string, mixed>|string $params Bound parameters, or the fetch mode when there are none.
     */
    public static function executeSql(string|array $sql, array|string $params = [], ?string $fetchMode = 'fetchAll'): bool|array|int
    {
        if (is_array($sql)) {
            $sql = implode(' ', $sql);
        }
        if (is_string($params)) {
            $fetchMode = $params;
            $params = [];
        }

        $stmt = self::conn()->prepare(trim($sql));
        $stmt->execute($params);

        return match ($fetchMode) {
            'fetch' => $stmt->fetch(PDO::FETCH_ASSOC) ?: [],
            'rowCount' => $stmt->rowCount(),
            'lastId' => (int) self::conn()->lastInsertId(),
            default => $stmt->fetchAll(PDO::FETCH_ASSOC),
        };
    }
}

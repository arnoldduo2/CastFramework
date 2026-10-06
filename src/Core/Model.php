<?php

declare(strict_types=1);

namespace Cast\Core;

use Cast\Contracts\ModelContract;

/**
 * Base model: a table name derived from the class name (`JournalEntries` => `journal_entries`, override with
 * `protected static ?string $table`), the query builder, and generic CRUD helpers.
 *
 *   class Users extends Model {
 *       public static function active(): array { return static::query()->where('active', 1)->get(); }
 *   }
 */
abstract class Model implements ModelContract
{
    protected static ?string $table = null;

    public static function tableName(): string
    {
        if (static::$table !== null) return static::$table;

        $name = substr(strrchr('\\' . static::class, '\\'), 1);
        return strtolower(preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $name));
    }

    public static function query(): QueryBuilder
    {
        return new QueryBuilder(static::tableName());
    }

    /** A builder for another table (default: this model's). */
    public static function table(?string $table = null): QueryBuilder
    {
        return new QueryBuilder($table ?? static::tableName());
    }

    /** Raw SQL with bound parameters. */
    public static function raw(string $sql, array $params = [], string $fetchMode = 'fetchAll'): bool|array|int
    {
        return QueryBuilder::executeSql($sql, $params, $fetchMode);
    }

    public static function getAll(mixed $active = null, string $activeColumn = 'active', string $orderBy = 'id', string $direction = 'ASC'): array
    {
        $query = static::query();
        if ($active !== null) $query->where($activeColumn, $active);
        return $query->orderBy($orderBy, $direction)->get();
    }

    public static function getOne(mixed $value, string $column = 'id'): array|false
    {
        return static::query()->where($column, $value)->first();
    }

    /** Is there a row where `$column = $value`? `$ignoreId` skips one row (for updates). */
    public static function exists(string $column, mixed $value, mixed $ignoreId = null, string $idColumn = 'id'): bool
    {
        $query = static::query()->where($column, $value);
        if ($ignoreId !== null) $query->and($idColumn, '!=', $ignoreId);
        return $query->exists();
    }

    public static function updateColumns(array $columns, mixed $value, string $column = 'id'): int
    {
        return static::query()->where($column, $value)->update($columns);
    }

    public static function setActive(int $active, mixed $value, string $column = 'id', string $activeColumn = 'active'): int
    {
        return static::query()->where($column, $value)->update([$activeColumn => $active]);
    }

    public static function deleteRows(mixed $value, string $column = 'id'): int
    {
        return static::query()->where($column, $value)->delete();
    }

    /** @return array{data: list<array>, total: int, page: int, per_page: int, last_page: int} */
    public static function paginate(int $page = 1, int $perPage = 15, ?string $orderBy = null, string $direction = 'ASC'): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $total = static::query()->count();

        $query = static::query()->limit($perPage, ($page - 1) * $perPage);
        if ($orderBy !== null) $query->orderBy($orderBy, $direction);

        return [
            'data' => $query->get(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    public static function beginTrans(): bool
    {
        return QueryBuilder::beginTrans();
    }

    public static function commitTrans(): bool
    {
        return QueryBuilder::commitTrans();
    }

    public static function rollbackTrans(): bool
    {
        return QueryBuilder::rollbackTrans();
    }

    public static function inTransaction(): bool
    {
        return QueryBuilder::inTransaction();
    }
}

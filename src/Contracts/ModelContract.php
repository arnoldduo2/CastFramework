<?php

declare(strict_types=1);

namespace Cast\Contracts;

use Cast\Core\QueryBuilder;

/**
 * The generic CRUD surface framework code (services, validation rules) relies on.
 * Implemented by {@see \Cast\Core\Model}.
 */
interface ModelContract
{
    public static function query(): QueryBuilder;

    public static function table(?string $table = null): QueryBuilder;

    public static function tableName(): string;

    /** @param array<string, mixed> $conditions column => value */
    public static function getAll(mixed $active = null, string $activeColumn = 'active', string $orderBy = 'id', string $direction = 'ASC'): array;

    public static function getOne(mixed $value, string $column = 'id'): array|false;

    public static function exists(string $column, mixed $value, mixed $ignoreId = null, string $idColumn = 'id'): bool;

    public static function updateColumns(array $columns, mixed $value, string $column = 'id'): int;

    public static function setActive(int $active, mixed $value, string $column = 'id', string $activeColumn = 'active'): int;

    public static function deleteRows(mixed $value, string $column = 'id'): int;

    public static function paginate(int $page = 1, int $perPage = 15, ?string $orderBy = null, string $direction = 'ASC'): array;

    public static function beginTrans(): bool;

    public static function commitTrans(): bool;

    public static function rollbackTrans(): bool;

    public static function inTransaction(): bool;
}

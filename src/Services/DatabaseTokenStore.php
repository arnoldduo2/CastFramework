<?php

declare(strict_types=1);

namespace Cast\Services;

use Cast\Contracts\TokenStore;
use Cast\Core\QueryBuilder;

/** Keeps API tokens in a database table (create it with {@see ApiTokenSchema} or `php cast token:schema --run`). */
final class DatabaseTokenStore implements TokenStore
{
    public function __construct(private string $table = 'api_tokens') {}

    public function create(array $record): void
    {
        $record['abilities'] = json_encode($record['abilities'] ?? ['*']);
        QueryBuilder::table($this->table)->insert($record);
    }

    public function find(string $id): ?array
    {
        $row = QueryBuilder::table($this->table)->where('id', $id)->first();
        if (!$row) return null;

        $row['abilities'] = json_decode((string) $row['abilities'], true) ?: [];
        return $row;
    }

    public function touch(string $id, int $time): void
    {
        QueryBuilder::table($this->table)->where('id', $id)->update(['last_used_at' => $time]);
    }

    public function revoke(string $id, int $time): bool
    {
        return QueryBuilder::table($this->table)->where('id', $id)->and('revoked_at', null)->update(['revoked_at' => $time]) > 0;
    }

    public function revokeAllFor(string|int $userId, int $time): int
    {
        return QueryBuilder::table($this->table)->where('user_id', (string) $userId)->and('revoked_at', null)->update(['revoked_at' => $time]);
    }
}

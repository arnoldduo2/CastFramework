<?php

declare(strict_types=1);

namespace Cast\Contracts;

/**
 * Where API tokens are kept. Only a hash of the secret is ever stored (see {@see \Cast\Services\ApiTokens}).
 * A token record: id, user_id, name, token_hash, abilities (list), created_at, last_used_at, expires_at, revoked_at
 * (the times are unix timestamps or null).
 */
interface TokenStore
{
    /** @param array<string, mixed> $record */
    public function create(array $record): void;

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array;

    public function touch(string $id, int $time): void;

    public function revoke(string $id, int $time): bool;

    /** Revoke every active token of a user. @return int how many were revoked */
    public function revokeAllFor(string|int $userId, int $time): int;
}

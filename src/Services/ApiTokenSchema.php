<?php

declare(strict_types=1);

namespace Cast\Services;

/** The `api_tokens` table. There is no migration layer: run this SQL once (or `php cast token:schema --run`). */
final class ApiTokenSchema
{
    public static function sql(string $driver = 'mysql', string $table = 'api_tokens'): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            throw new \InvalidArgumentException("Invalid table name \"$table\".");
        }
        $big = $driver === 'sqlite' ? 'INTEGER' : 'BIGINT';
        $engine = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

        return "CREATE TABLE IF NOT EXISTS $table (\n"
            . "    id VARCHAR(32) NOT NULL PRIMARY KEY,\n"
            . "    user_id VARCHAR(64) NOT NULL,\n"
            . "    name VARCHAR(100) NOT NULL,\n"
            . "    token_hash CHAR(64) NOT NULL,\n"
            . "    abilities TEXT NOT NULL,\n"
            . "    created_at $big NOT NULL,\n"
            . "    last_used_at $big NULL,\n"
            . "    expires_at $big NULL,\n"
            . "    revoked_at $big NULL\n"
            . ")$engine";
    }
}

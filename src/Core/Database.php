<?php

declare(strict_types=1);

namespace Cast\Core;

use InvalidArgumentException;
use PDO;
use PDOException;

/**
 * The shared PDO connection, created lazily from `config('database')`:
 * driver (mysql|pgsql|sqlite), host, port, name, user, pass, charset, dsn (overrides the rest), options.
 * Native prepared statements are always on (no emulation).
 */
final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        return self::$connection ??= self::connect((array) Config::get('database', []));
    }

    /** Use an existing PDO (tests, or an app with its own connection setup). */
    public static function use(PDO $pdo): void
    {
        self::$connection = $pdo;
    }

    public static function reset(): void
    {
        self::$connection = null;
    }

    /** @param array<string, mixed> $config */
    public static function connect(array $config): PDO
    {
        $dsn = (string) ($config['dsn'] ?? self::dsn($config));
        try {
            $pdo = new PDO($dsn, $config['user'] ?? null, $config['pass'] ?? null, (array) ($config['options'] ?? []));
        } catch (PDOException $e) {
            // never leak the DSN or credentials in the message
            throw new PDOException('Could not connect to the database: ' . $e->getMessage(), (int) $e->getCode());
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        return $pdo;
    }

    /** @param array<string, mixed> $config */
    private static function dsn(array $config): string
    {
        $driver = rtrim(strtolower((string) ($config['driver'] ?? 'mysql')), ':');
        $name = (string) ($config['name'] ?? '');

        return match ($driver) {
            'sqlite' => 'sqlite:' . ($name !== '' ? $name : ':memory:'),
            'mysql' => sprintf(
                'mysql:host=%s;%sdbname=%s;charset=%s',
                $config['host'] ?? '127.0.0.1',
                isset($config['port']) && $config['port'] !== '' ? 'port=' . $config['port'] . ';' : '',
                $name,
                $config['charset'] ?? 'utf8mb4'
            ),
            'pgsql' => sprintf(
                'pgsql:host=%s;%sdbname=%s',
                $config['host'] ?? '127.0.0.1',
                isset($config['port']) && $config['port'] !== '' ? 'port=' . $config['port'] . ';' : '',
                $name
            ),
            default => throw new InvalidArgumentException("Unsupported database driver \"$driver\"."),
        };
    }
}

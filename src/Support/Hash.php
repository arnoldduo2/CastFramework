<?php

declare(strict_types=1);

namespace Cast\Support;

use Cast\Core\Config;

/**
 * Password hashing. Argon2id (memory-hard, the current recommendation) when this PHP has it, otherwise bcrypt. Both are salted by PHP;
 * `check()` reads the algorithm from the stored hash, so hashes made by older apps (bcrypt `$2y$`/`$2a$`) keep working, and
 * `needsRehash()` tells you when to store a stronger one (Auth does it at login when the user provider has a `rehash()` method).
 *
 *   $hash = Hash::make('secret');          // store this, never the password
 *   Hash::check('secret', $hash);          // true / false
 *   Hash::needsRehash($hash);              // true when the algorithm or cost is below your settings
 *
 * Settings (config/hashing.php, `php cast make:config hashing`): driver `auto` | `argon2id` | `bcrypt`, bcrypt `cost`, argon2 `memory`, `time`, `threads`.
 * For values you must read back (tokens, card numbers) use Crypt::encrypt() with the app key instead: a hash cannot be reversed.
 */
final class Hash
{
    public static function make(string $password): string
    {
        [$algo, $options] = self::algorithm();
        return password_hash($password, $algo, $options);
    }

    public static function check(string $password, string $hash): bool
    {
        return $hash !== '' && password_verify($password, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        [$algo, $options] = self::algorithm();
        return password_needs_rehash($hash, $algo, $options);
    }

    /** The algorithm in use: 'argon2id' or 'bcrypt'. */
    public static function driver(): string
    {
        return self::algorithm()[0] === PASSWORD_BCRYPT ? 'bcrypt' : 'argon2id';
    }

    /** @return array{0: string, 1: array<string, int>} */
    private static function algorithm(): array
    {
        $driver = (string) Config::get('hashing.driver', 'auto');
        $argon = defined('PASSWORD_ARGON2ID') && in_array('argon2id', password_algos(), true);
        if ($driver === 'argon2id' && !$argon) $driver = 'bcrypt';        // not available in this PHP build
        if ($driver === 'auto') $driver = $argon ? 'argon2id' : 'bcrypt';

        if ($driver === 'bcrypt') return [PASSWORD_BCRYPT, ['cost' => max(10, (int) Config::get('hashing.bcrypt.cost', 12))]];
        return [PASSWORD_ARGON2ID, [
            'memory_cost' => (int) Config::get('hashing.argon.memory', 65536),
            'time_cost' => (int) Config::get('hashing.argon.time', 4),
            'threads' => (int) Config::get('hashing.argon.threads', 1),
        ]];
    }
}

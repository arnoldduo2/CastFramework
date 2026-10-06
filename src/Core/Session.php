<?php

declare(strict_types=1);

namespace Cast\Core;

/**
 * Session access plus flash data and the global CSRF token.
 * In the CLI (tests, console) no PHP session is started; `$_SESSION` is used as a plain array.
 */
final class Session
{
    private const CSRF_KEY = '_csrf_token';
    private const FLASH_KEY = '_flash';

    public static function init(): void
    {
        if (PHP_SAPI === 'cli' || session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION ??= [];
            self::ageFlash();
            return;
        }

        $config = (array) Config::get('session', []);
        session_name((string) ($config['name'] ?? 'cast_session'));
        session_set_cookie_params([
            'lifetime' => (int) ($config['lifetime'] ?? 0),
            'path' => (string) ($config['path'] ?? '/'),
            'domain' => (string) ($config['domain'] ?? ''),
            'secure' => (bool) ($config['secure'] ?? false),
            'httponly' => (bool) ($config['httponly'] ?? true),
            'samesite' => (string) ($config['samesite'] ?? 'Lax'),
        ]);
        @session_start();
        self::ageFlash();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public static function clear(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** Read a value and remove it. */
    public static function pull(string $key, mixed $default = null): mixed
    {
        $value = $_SESSION[$key] ?? $default;
        unset($_SESSION[$key]);
        return $value;
    }

    // ------------------------------------------------------------------ flash

    /**
     * Keep a value for the next request (alerts, validation errors, old input). It is removed when read,
     * and dropped anyway after that next request if nobody read it.
     */
    public static function flash(string $key, mixed $value): void
    {
        $_SESSION[self::FLASH_KEY][$key] = ['v' => $value, 'age' => 0];
    }

    /** Read and remove a flashed value. */
    public static function getFlash(string $key, mixed $default = null): mixed
    {
        $value = self::peekFlash($key, $default);
        unset($_SESSION[self::FLASH_KEY][$key]);
        return $value;
    }

    /** Read a flashed value without removing it. */
    public static function peekFlash(string $key, mixed $default = null): mixed
    {
        return $_SESSION[self::FLASH_KEY][$key]['v'] ?? $default;
    }

    public static function hasFlash(string $key): bool
    {
        return isset($_SESSION[self::FLASH_KEY][$key]);
    }

    /** Called at the start of every request: flash data lives for the request that follows the one that set it. */
    private static function ageFlash(): void
    {
        foreach ($_SESSION[self::FLASH_KEY] ?? [] as $key => $item) {
            if (++$_SESSION[self::FLASH_KEY][$key]['age'] > 1) unset($_SESSION[self::FLASH_KEY][$key]);
        }
    }

    // ------------------------------------------------------------------- CSRF

    /** The one global CSRF token for this session (created on first use). */
    public static function csrfToken(): string
    {
        if (empty($_SESSION[self::CSRF_KEY])) {
            $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::CSRF_KEY];
    }

    /** New session id and a new CSRF token. Call after login/logout. */
    public static function regenerate(): void
    {
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(32));
    }

    /** @param list<string> $keys Keys to remove before the session is destroyed. */
    public static function destroy(array $keys = []): void
    {
        foreach ($keys as $key) unset($_SESSION[$key]);
        $_SESSION = [];
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}

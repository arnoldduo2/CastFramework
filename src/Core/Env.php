<?php

declare(strict_types=1);

namespace Cast\Core;

use InvalidArgumentException;

/**
 * Reads the `.env` file once and caches it.
 *
 * Supports `KEY=value`, `export KEY=value`, quoted values ("..." with \n, '...' literal),
 * `# comments` (whole line, or after an unquoted value) and blank lines. Values `true`, `false`,
 * `null` and `empty` are cast. Keys are case-insensitive. Real environment variables
 * (getenv / $_ENV / $_SERVER) are used when the file has no entry for the key.
 */
final class Env
{
    /** @var array<string, string|bool|null>|null */
    private static ?array $values = null;
    private static ?string $path = null;

    public static function load(string $path): void
    {
        self::$path = $path;
        self::$values = is_file($path) ? self::parse((string) file_get_contents($path)) : [];
    }

    /** Forget everything (tests, or to re-read the file). */
    public static function reset(): void
    {
        self::$values = null;
        self::$path = null;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $key = strtoupper($key);
        $values = self::values();
        if (array_key_exists($key, $values)) return $values[$key];

        $real = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($real !== false && $real !== null) return self::cast((string) $real);

        return $default;
    }

    public static function has(string $key): bool
    {
        $key = strtoupper($key);
        return array_key_exists($key, self::values()) || getenv($key) !== false;
    }

    /** Truthy/falsey string matching: '', '0', 'false', 'off', 'no' are false; anything else true. */
    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) return $default;
        if (is_bool($value)) return $value;
        return !in_array(strtolower(trim((string) $value)), ['', '0', 'false', 'off', 'no'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);
        return is_numeric($value) ? (int) $value : $default;
    }

    /** @return array<string, string|bool|null> */
    public static function all(): array
    {
        return self::values();
    }

    /** Set a value in memory, and write it to the `.env` file when one is loaded. */
    public static function set(string $key, string|int|bool $value, bool $persist = true): void
    {
        if (str_contains($key, '=')) {
            throw new InvalidArgumentException('Invalid environment variable key. Key cannot contain an equals sign.');
        }
        $key = strtoupper($key);
        self::values();
        self::$values[$key] = is_string($value) ? self::cast($value) : $value;

        if (!$persist || self::$path === null || !is_file(self::$path)) return;

        $text = (string) file_get_contents(self::$path);
        $line = $key . '=' . (is_bool($value) ? ($value ? 'true' : 'false') : self::quote((string) $value));
        $count = 0;
        $text = preg_replace('/^(?:export\s+)?' . preg_quote($key, '/') . '\s*=.*$/mi', addcslashes($line, '\\$'), $text, 1, $count);
        if ($count === 0) $text = rtrim($text, "\r\n") . "\n" . $line . "\n";
        file_put_contents(self::$path, $text, LOCK_EX);
    }

    /** @return array<string, string|bool|null> */
    private static function values(): array
    {
        if (self::$values !== null) return self::$values;

        $app = \Cast\App\Application::instance();
        self::load($app ? $app->basePath('.env') : getcwd() . DIRECTORY_SEPARATOR . '.env');
        return self::$values;
    }

    /** @return array<string, string|bool|null> */
    private static function parse(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/', $text) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (str_starts_with($line, 'export ')) $line = ltrim(substr($line, 7));

            $eq = strpos($line, '=');
            if ($eq === false) continue;

            $key = strtoupper(trim(substr($line, 0, $eq)));
            if ($key === '' || !preg_match('/^[A-Z0-9_.\-]+$/', $key)) continue;

            $out[$key] = self::parseValue(trim(substr($line, $eq + 1)));
        }
        return $out;
    }

    private static function parseValue(string $raw): string|bool|null
    {
        if ($raw === '') return '';

        $quote = $raw[0];
        if ($quote === '"' || $quote === "'") {
            $end = strpos($raw, $quote, 1);
            while ($quote === '"' && $end !== false && $raw[$end - 1] === '\\') {
                $end = strpos($raw, $quote, $end + 1);
            }
            $inner = $end === false ? substr($raw, 1) : substr($raw, 1, $end - 1);
            return $quote === '"' ? str_replace(['\\n', '\\r', '\\t', '\\"', '\\\\'], ["\n", "\r", "\t", '"', '\\'], $inner) : $inner;
        }

        // unquoted: strip a trailing " # comment"
        $hash = strpos($raw, ' #');
        if ($hash !== false) $raw = rtrim(substr($raw, 0, $hash));

        return self::cast($raw);
    }

    private static function cast(string $value): string|bool|null
    {
        return match (strtolower($value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }

    private static function quote(string $value): string
    {
        return preg_match('/[\s#"\'\\\\]/', $value) ? '"' . addcslashes($value, "\"\\") . '"' : $value;
    }
}

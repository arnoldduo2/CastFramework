<?php

declare(strict_types=1);

namespace Cast\Core;

/**
 * Configuration repository with dot notation: `Config::get('app.name')`.
 * Every `*.php` file in the config folder that returns an array becomes a top-level key (its file name), merged over
 * the framework defaults: associative arrays merge key by key, lists and scalars replace.
 */
final class Config
{
    /** @var array<string, mixed> */
    private static array $items = [];

    public static function load(string $dir): void
    {
        foreach (glob(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            $value = require $file;
            if (is_array($value)) {
                // the app's file is merged over the framework defaults: it only needs to list what it changes
                $name = basename($file, '.php');
                self::$items[$name] = self::merge(self::$items[$name] ?? [], $value);
            }
        }
    }

    /** Merge defaults under whatever is already set (existing values win). */
    public static function defaults(array $defaults): void
    {
        self::$items = self::merge($defaults, self::$items);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) return $default;
            $value = $value[$segment];
        }
        return $value;
    }

    public static function has(string $key): bool
    {
        $marker = new \stdClass();
        return self::get($key, $marker) !== $marker;
    }

    public static function set(string $key, mixed $value): void
    {
        $ref = &self::$items;
        foreach (explode('.', $key) as $segment) {
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) $ref[$segment] = [];
            $ref = &$ref[$segment];
        }
        $ref = $value;
    }

    /** @return array<string, mixed> */
    public static function all(): array
    {
        return self::$items;
    }

    public static function reset(): void
    {
        self::$items = [];
    }

    private static function merge(array $base, array $over): array
    {
        foreach ($over as $key => $value) {
            $base[$key] = is_array($value) && isset($base[$key]) && is_array($base[$key]) && !array_is_list($value)
                ? self::merge($base[$key], $value)
                : $value;
        }
        return $base;
    }
}

<?php

declare(strict_types=1);

namespace Cast\Support;

/**
 * What this PHP needs to run the framework, and what production wants on top. Used by `php cast requirements` and `php cast deploy:check`.
 * Each result is  [status, label, how to fix]  with status 'ok', 'warn' (works, but you should change it) or 'fail' (will not work).
 *
 *   foreach (Requirements::check('mysql', production: true) as [$status, $label, $fix]) ...
 */
final class Requirements
{
    public const MINIMUM_PHP = '8.1.0';

    /**
     * @param string $driver the database driver in use (sqlite, mysql, pgsql): its PDO extension is required
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public static function check(string $driver = '', bool $production = false): array
    {
        $r = [];
        $add = function (string $status, string $label, string $fix = '') use (&$r): void { $r[] = [$status, $label, $fix]; };

        $add(version_compare(PHP_VERSION, self::MINIMUM_PHP, '>=') ? 'ok' : 'fail', 'PHP ' . self::MINIMUM_PHP . ' or newer (running ' . PHP_VERSION . ')', 'upgrade PHP');
        foreach (['pdo' => 'database access', 'mbstring' => 'text handling', 'json' => 'JSON', 'openssl' => 'encrypt(), sign() and HTTPS', 'ctype' => 'validation rules', 'session' => 'sessions and CSRF'] as $ext => $why) {
            $add(extension_loaded($ext) ? 'ok' : 'fail', "extension $ext ($why)", "enable extension=$ext in php.ini");
        }
        $pdo = ['sqlite' => 'pdo_sqlite', 'mysql' => 'pdo_mysql', 'pgsql' => 'pdo_pgsql'][$driver] ?? '';
        if ($pdo !== '') $add(extension_loaded($pdo) ? 'ok' : 'fail', "extension $pdo (the $driver database)", "enable extension=$pdo in php.ini");

        foreach (['sodium' => 'Argon2id password hashing and modern crypto', 'fileinfo' => 'file upload type checks', 'curl' => 'calling other services', 'zip' => 'zip files (reports, backups)', 'gd' => 'images, QR codes and barcodes', 'intl' => 'locale-aware dates and numbers'] as $ext => $why) {
            $add(extension_loaded($ext) ? 'ok' : 'warn', "extension $ext ($why)", "optional: enable extension=$ext");
        }
        $add(in_array('argon2id', password_algos(), true) ? 'ok' : 'warn', 'Argon2id available (else bcrypt is used)', 'build PHP with argon2 or install sodium');

        $memory = self::bytes((string) ini_get('memory_limit'));
        $add($memory === -1 || $memory >= 128 * 1048576 ? 'ok' : 'warn', 'memory_limit is 128M or more (now ' . ini_get('memory_limit') . ')', 'raise memory_limit in php.ini');
        $add(self::bytes((string) ini_get('upload_max_filesize')) >= 2 * 1048576 ? 'ok' : 'warn', 'upload_max_filesize is 2M or more (now ' . ini_get('upload_max_filesize') . ')', 'raise upload_max_filesize and post_max_size');
        $add((string) ini_get('date.timezone') !== '' || $production ? 'ok' : 'warn', 'a time zone is set (APP_TIMEZONE in .env is used otherwise)', 'set APP_TIMEZONE=UTC (or yours)');

        if ($production) {
            $add(!filter_var(ini_get('display_errors'), FILTER_VALIDATE_BOOLEAN) || ini_get('display_errors') === 'stderr' ? 'ok' : 'warn', 'display_errors is off (errors must not reach visitors)', 'display_errors=Off in php.ini');
            $add(!filter_var(ini_get('expose_php'), FILTER_VALIDATE_BOOLEAN) ? 'ok' : 'warn', 'expose_php is off (hides the PHP version header)', 'expose_php=Off');
            $opcache = extension_loaded('Zend OPcache') && filter_var(ini_get(PHP_SAPI === 'cli' ? 'opcache.enable_cli' : 'opcache.enable'), FILTER_VALIDATE_BOOLEAN);
            $add($opcache || PHP_SAPI === 'cli' ? 'ok' : 'warn', 'OPcache is on (much faster PHP)', 'opcache.enable=1');
            $add(filter_var(ini_get('session.use_strict_mode'), FILTER_VALIDATE_BOOLEAN) ? 'ok' : 'warn', 'session.use_strict_mode is on', 'session.use_strict_mode=1');
            $add(!filter_var(ini_get('allow_url_include'), FILTER_VALIDATE_BOOLEAN) ? 'ok' : 'fail', 'allow_url_include is off', 'allow_url_include=Off');
        }
        return $r;
    }

    /** Count of ['fail'] results. */
    public static function failures(array $results): int
    {
        return count(array_filter($results, fn($x) => $x[0] === 'fail'));
    }

    /** php.ini sizes ("128M", "1G", "-1") in bytes. */
    public static function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') return -1;
        $n = (int) $value;
        return match (strtolower(substr($value, -1))) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n };
    }
}

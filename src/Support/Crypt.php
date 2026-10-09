<?php

declare(strict_types=1);

namespace Cast\Support;

use RuntimeException;

/**
 * Signing and encryption with the app key (`APP_KEY` in .env, made by `php cast key:generate`).
 *
 *   $signed = Crypt::sign('user:42');            // "user:42.<hmac>"   tamper-proof, readable
 *   Crypt::unsign($signed);                      // "user:42", or null when it was changed
 *   $secret = Crypt::encrypt('card number');     // AES-256-GCM, unreadable and tamper-proof
 *   Crypt::decrypt($secret);                     // the text, or null when it is not valid for this key
 *
 * Changing the key makes every earlier signature and encrypted value invalid.
 */
final class Crypt
{
    /** @return string 32 raw bytes derived from the key setting (`base64:...` or any long random string) */
    public static function key(?string $key = null): string
    {
        $key ??= (string) \Cast\Core\Config::get('app.key', '');
        if ($key === '') throw new RuntimeException('APP_KEY is not set. Run  php cast key:generate');
        if (str_starts_with($key, 'base64:')) {
            $raw = base64_decode(substr($key, 7), true);
            if ($raw === false || strlen($raw) < 16) throw new RuntimeException('APP_KEY is not a valid base64 key. Run  php cast key:generate --force');
            return strlen($raw) === 32 ? $raw : hash('sha256', $raw, true);
        }
        return hash('sha256', $key, true);
    }

    /** A new random key in the form written to .env. */
    public static function generateKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    public static function sign(string $value, ?string $key = null): string
    {
        return $value . '.' . self::b64(hash_hmac('sha256', $value, self::key($key), true));
    }

    public static function unsign(string $signed, ?string $key = null): ?string
    {
        $dot = strrpos($signed, '.');
        if ($dot === false) return null;
        $value = substr($signed, 0, $dot);
        return hash_equals(self::sign($value, $key), $signed) ? $value : null;
    }

    public static function encrypt(string $plain, ?string $key = null): string
    {
        $iv = random_bytes(12);
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key($key), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) throw new RuntimeException('Encryption failed.');
        return 'v1.' . self::b64($iv . $tag . $cipher);
    }

    public static function decrypt(string $payload, ?string $key = null): ?string
    {
        if (!str_starts_with($payload, 'v1.')) return null;
        $raw = base64_decode(strtr(substr($payload, 3), '-_', '+/'), true);
        if ($raw === false || strlen($raw) < 29) return null;
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key($key), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $plain === false ? null : $plain;
    }

    private static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}

<?php

declare(strict_types=1);

use Cast\App\Application;
use Cast\Contracts\Guard;
use Cast\Core\Session;
use Cast\Http\HttpException;
use Cast\Http\Request;

if (!function_exists('__csrf')) {
    /** The hidden CSRF input every form needs: `<?= __csrf() ?>`. */
    function __csrf(): string
    {
        return "<input type='hidden' name='_token' value='" . Session::csrfToken() . "'/>";
    }
}

if (!function_exists('__verifyCsrf')) {
    /**
     * Check a CSRF token (a string, or request data containing `_token`). Throws a 419 HttpException when it is
     * wrong; the Router already does this for POST/PUT/PATCH/DELETE routes.
     */
    function __verifyCsrf(array|string $token = [], bool $ajaxRequest = true): bool
    {
        $given = is_array($token)
            ? (string) ($token['_token'] ?? $token['token'] ?? $token['csrf_token'] ?? $token['csrf'] ?? '')
            : $token;
        if ($given === '') $given = Request::current()->csrfToken();

        if ($given !== '' && hash_equals(Session::csrfToken(), $given)) return true;
        throw new HttpException(419, 'CSRF verification failed or the token has expired.');
    }
}

if (!function_exists('hashPassword')) {
    function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }
}

if (!function_exists('verifyPassword')) {
    function verifyPassword(string $password, string $hashed): bool
    {
        return password_verify($password, $hashed);
    }
}

if (!function_exists('__randStr')) {
    /** A random string of letters and digits (cryptographically secure). */
    function __randStr(int $length = 11, bool $upper = true): string
    {
        $chars = '0123456789abcdefghijklmnopqrstuvwxyz';
        $key = '';
        for ($i = 0; $i < $length; $i++) {
            $key .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $upper ? strtoupper($key) : $key;
    }
}

if (!function_exists('tokenGen')) {
    /** A token like `ab12-cd34ef-...` (five random groups). */
    function tokenGen(): string
    {
        $parts = [];
        for ($i = 0; $i < 5; $i++) $parts[] = __randStr(random_int(4, 9), false);
        return implode('-', $parts);
    }
}

if (!function_exists('__getUser')) {
    /** The logged-in user from the bound Guard, or one key of it. */
    function __getUser(?string $key = null): array|string|int|null
    {
        $app = Application::instance();
        $guard = $app && $app->has('guard') ? $app->make('guard') : null;
        $user = $guard instanceof Guard ? $guard->user() : null;
        if ($user === null || $key === null) return $user;
        return $user[$key] ?? null;
    }
}

if (!function_exists('_checkAccess')) {
    /** Stop with 403 unless the Guard grants one of these permission slugs. */
    function _checkAccess(array|string $permissions): void
    {
        $app = Application::instance();
        $guard = $app && $app->has('guard') ? $app->make('guard') : null;
        if (!$guard instanceof Guard || !$guard->can($permissions)) {
            throw new HttpException(403, 'You do not have the required permission: ' . implode(', ', (array) $permissions));
        }
    }
}

if (!function_exists('app_key')) {
    /** The app key setting (`APP_KEY`), or '' when it was not generated yet. */
    function app_key(): string
    {
        return (string) \Cast\Core\Config::get('app.key', '');
    }
}

if (!function_exists('sign')) {
    /** `sign('user:42')` => "user:42.<signature>": readable but tamper-proof (uses APP_KEY). */
    function sign(string $value): string
    {
        return \Cast\Support\Crypt::sign($value);
    }
}

if (!function_exists('unsign')) {
    /** The value of a `sign()`ed string, or null when it was changed. */
    function unsign(string $signed): ?string
    {
        return \Cast\Support\Crypt::unsign($signed);
    }
}

if (!function_exists('encrypt')) {
    /** Encrypt a string with APP_KEY (AES-256-GCM). */
    function encrypt(string $plain): string
    {
        return \Cast\Support\Crypt::encrypt($plain);
    }
}

if (!function_exists('decrypt')) {
    /** The text of an `encrypt()`ed string, or null when it is not valid for this key. */
    function decrypt(string $payload): ?string
    {
        return \Cast\Support\Crypt::decrypt($payload);
    }
}

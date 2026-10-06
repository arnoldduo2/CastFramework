<?php

declare(strict_types=1);

namespace Cast\Services;

use Cast\Contracts\Guard;
use Cast\Core\Config;
use Cast\Core\Session;

/**
 * Default {@see Guard}: the user array lives in the session under `auth.session_key`; permission slugs are the
 * array under `auth.permissions_key` inside that user. Apps with their own rules bind their own `guard`.
 */
final class SessionGuard implements Guard
{
    public function user(): ?array
    {
        $user = Session::get((string) Config::get('auth.session_key', 'login'));
        return is_array($user) && $user ? $user : null;
    }

    public function check(string $guard): bool
    {
        return match ($guard) {
            'private' => $this->user() !== null,
            'auth' => $this->user() === null,
            default => true,
        };
    }

    public function can(string|array $permission): bool
    {
        $user = $this->user();
        if ($user === null) return false;
        $have = (array) ($user[(string) Config::get('auth.permissions_key', 'permissions')] ?? []);
        foreach ((array) $permission as $slug) {
            if (in_array($slug, $have, true)) return true;
        }
        return false;
    }
}

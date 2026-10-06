<?php

declare(strict_types=1);

namespace Cast\Services;

use Cast\Contracts\UserProvider;
use Cast\Core\Config;
use Cast\Core\Session;

/**
 * Log users in and out. The app's own storage is reached through a {@see UserProvider}.
 * What happens next (redirect, alert) is up to the controller.
 *
 *   $auth = new Auth(new MyUserProvider());
 *   if ($auth->attempt($email, $password)) { return $this->redirect('/dashboard'); }
 */
final class Auth
{
    public function __construct(private UserProvider $users, private ?string $sessionKey = null) {}

    /**
     * Check the credentials without logging anyone in (no session): for issuing API tokens.
     * @return array|null The user without the password hash, or null when the details are wrong.
     */
    public function verify(string $identifier, string $password): ?array
    {
        $user = $this->users->findByCredentials($identifier);
        $key = $this->users->passwordKey();
        if (!$user || !isset($user[$key]) || !password_verify($password, (string) $user[$key])) {
            return null;
        }
        unset($user[$key]);
        return $user;
    }

    /** Check the credentials; on success start a fresh session and store the user (without the password hash). */
    public function attempt(string $identifier, string $password): bool
    {
        $user = $this->verify($identifier, $password);
        if ($user === null) return false;

        Session::regenerate();
        Session::set($this->key(), $user);
        $this->users->onLogin($user);
        return true;
    }

    public function provider(): UserProvider
    {
        return $this->users;
    }

    public function logout(): void
    {
        Session::clear($this->key());
        Session::regenerate();
    }

    public function user(): ?array
    {
        $user = Session::get($this->key());
        return is_array($user) && $user ? $user : null;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_DEFAULT);
    }

    private function key(): string
    {
        return $this->sessionKey ?? (string) Config::get('auth.session_key', 'login');
    }
}

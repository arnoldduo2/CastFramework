<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Users;
use Cast\Contracts\FindsUsersById;
use Cast\Contracts\UserProvider;

/** Tells the framework's Auth service where users live (the `users` table). */
final class UserStore implements UserProvider, FindsUsersById
{
    public function findByCredentials(string $identifier): ?array
    {
        $user = Users::getOne($identifier, 'email');
        return $user ? $this->prepare($user) : null;
    }

    /** Used by API tokens: a token stores the user's id. */
    public function findById(string|int $id): ?array
    {
        $user = Users::getOne($id);
        if (!$user) return null;

        unset($user['password']);
        return $this->prepare($user);
    }

    public function onLogin(array $user): void
    {
        Users::updateColumns(['last_login' => date('Y-m-d H:i:s')], $user['id']);
    }

    public function passwordKey(): string
    {
        return 'password';
    }

    // permissions are stored as JSON text; the Guard reads them as a list of slugs
    private function prepare(array $user): array
    {
        $user['permissions'] = json_decode((string) ($user['permissions'] ?? ''), true) ?: [];
        return $user;
    }
}

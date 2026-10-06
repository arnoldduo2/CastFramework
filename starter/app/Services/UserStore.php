<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Users;
use Cast\Contracts\UserProvider;

/** Tells the framework's Auth service where users live (the `users` table). */
final class UserStore implements UserProvider
{
    public function findByCredentials(string $identifier): ?array
    {
        $user = Users::getOne($identifier, 'email');
        if (!$user) return null;

        // permissions are stored as JSON text; the Guard reads them as a list of slugs
        $user['permissions'] = json_decode((string) $user['permissions'], true) ?: [];
        return $user;
    }

    public function onLogin(array $user): void
    {
        Users::updateColumns(['last_login' => date('Y-m-d H:i:s')], $user['id']);
    }

    public function passwordKey(): string
    {
        return 'password';
    }
}

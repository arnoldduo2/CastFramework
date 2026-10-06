<?php

declare(strict_types=1);

namespace Cast\Contracts;

/**
 * Implement this next to {@see UserProvider} so API tokens can be turned back into users:
 * a token stores the user's id, and each API request loads the user with it.
 */
interface FindsUsersById
{
    /** @return array|null The user (without the password hash), in the same shape `onLogin()` receives. */
    public function findById(string|int $id): ?array;
}

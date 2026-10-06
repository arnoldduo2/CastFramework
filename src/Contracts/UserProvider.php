<?php

declare(strict_types=1);

namespace Cast\Contracts;

/** How {@see \Cast\Services\Auth} finds users in the app's own storage. */
interface UserProvider
{
    /** @return array|null The user row (must contain the password hash under `passwordKey()`), or null. */
    public function findByCredentials(string $identifier): ?array;

    /** Called after a successful login, e.g. to mark the user online. */
    public function onLogin(array $user): void;

    /** Column/key in the user array that holds the password hash. */
    public function passwordKey(): string;
}

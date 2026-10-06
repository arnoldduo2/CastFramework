<?php

declare(strict_types=1);

namespace Cast\Contracts;

/** Authentication and permission checks. Apps bind their own; the framework ships a session-based default. */
interface Guard
{
    /** @param string $guard 'private' (needs login), 'auth' (guests only) or 'public'. */
    public function check(string $guard): bool;

    /** @param string|array<int, string> $permission One slug, or a list where any one is enough. */
    public function can(string|array $permission): bool;

    /** The logged-in user, or null. */
    public function user(): ?array;
}

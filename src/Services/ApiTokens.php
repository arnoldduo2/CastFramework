<?php

declare(strict_types=1);

namespace Cast\Services;

use Cast\Contracts\TokenStore;

/**
 * Personal API tokens for non-browser clients (a front-end framework, a mobile app, another server).
 *
 * A token looks like `{id}|{secret}`. The id finds the record; only the SHA-256 of the secret is stored, so a
 * leaked database does not leak usable tokens. The plain token is returned once, when it is issued.
 *
 *   $issued = app('tokens')->issue($user['id'], 'mobile app', ['items:read'], ttl: 30 * 86400);
 *   $issued['token'];   // "9f3c1a2b4d5e6f70|k3J..."  (show it once)
 */
final class ApiTokens
{
    /** @param callable(): int $clock */
    public function __construct(private TokenStore $store, private mixed $clock = null) {}

    /**
     * @param list<string> $abilities what the token may do (`*` = everything, `items:*` = a group, `items:read` = one)
     * @param ?int $ttl seconds until it expires, or null for no expiry
     * @return array{token: string, id: string, expires_at: ?int}
     */
    public function issue(string|int $userId, string $name = 'token', array $abilities = ['*'], ?int $ttl = null): array
    {
        $id = bin2hex(random_bytes(8));
        $secret = rtrim(strtr(base64_encode(random_bytes(30)), '+/', '-_'), '=');   // 40 URL-safe characters
        $now = $this->now();
        $expires = $ttl !== null && $ttl > 0 ? $now + $ttl : null;

        $this->store->create([
            'id' => $id,
            'user_id' => (string) $userId,
            'name' => mb_substr($name, 0, 100),
            'token_hash' => hash('sha256', $secret),
            'abilities' => array_values($abilities) ?: ['*'],
            'created_at' => $now,
            'last_used_at' => null,
            'expires_at' => $expires,
            'revoked_at' => null,
        ]);

        return ['token' => $id . '|' . $secret, 'id' => $id, 'expires_at' => $expires];
    }

    /** @return array<string, mixed>|null The token record when the plain token is valid, active and not expired. */
    public function authenticate(string $plain): ?array
    {
        [$id, $secret] = array_pad(explode('|', $plain, 2), 2, '');
        if ($id === '' || $secret === '' || !ctype_xdigit($id) || strlen($id) > 32) return null;

        $record = $this->store->find($id);
        if (!$record || !hash_equals((string) $record['token_hash'], hash('sha256', $secret))) return null;

        $now = $this->now();
        if (!empty($record['revoked_at']) || (!empty($record['expires_at']) && (int) $record['expires_at'] <= $now)) return null;

        // recording the last use is best effort and only once a minute, to keep reads cheap
        if (empty($record['last_used_at']) || $now - (int) $record['last_used_at'] >= 60) {
            $this->store->touch($id, $now);
        }
        return $record;
    }

    public function revoke(string $id): bool
    {
        return $this->store->revoke($id, $this->now());
    }

    public function revokeAllFor(string|int $userId): int
    {
        return $this->store->revokeAllFor($userId, $this->now());
    }

    /** Does a token's list of abilities cover `$needed`? `*` covers everything, `items:*` covers `items:read`. */
    public static function allows(array $abilities, string $needed): bool
    {
        foreach ($abilities as $have) {
            if ($have === '*' || $have === $needed) return true;
            if (str_ends_with((string) $have, ':*') && str_starts_with($needed, substr((string) $have, 0, -1))) return true;
        }
        return false;
    }

    private function now(): int
    {
        return is_callable($this->clock) ? (int) ($this->clock)() : time();
    }
}

<?php

declare(strict_types=1);

namespace Cast\Http\Middleware;

use Cast\App\Application;
use Cast\Contracts\FindsUsersById;
use Cast\Contracts\Guard;
use Cast\Contracts\Middleware;
use Cast\Http\HttpException;
use Cast\Http\Request;
use Cast\Http\Response;
use Cast\Services\ApiTokens;
use Cast\Services\Auth;

/**
 * Authenticates an API request: `Authorization: Bearer {id}|{secret}`, or (for same-site clients) the login session.
 *
 *   Router::middleware([ApiAuth::class], fn() => ...);                    // any valid token or session
 *   Router::middleware([ApiAuth::class, 'items:write'], fn() => ...);      // the token must also have this ability
 *
 * A bad, revoked or expired token is a 401 (never a fallback to the session). After it, `$request->user()` is the
 * token's user and `$request->token()` the token record; the `guard` (permission slugs) sees that user too.
 * Requests authenticated by a token do not need a CSRF token: it is not sent by the browser on its own.
 */
final class ApiAuth implements Middleware
{
    public function handle(Request $request, mixed ...$abilities): ?Response
    {
        $bearer = $request->bearerToken();

        if ($bearer !== null) {
            $app = Application::instance();
            /** @var ApiTokens $tokens */
            $tokens = $app->make('tokens');
            $record = $tokens->authenticate($bearer);
            if ($record === null) {
                throw new HttpException(401, 'The API token is invalid, revoked or expired.', ['WWW-Authenticate' => 'Bearer realm="api", error="invalid_token"']);
            }

            $user = $this->userFor($app, $record['user_id']);
            if ($user === null) {
                throw new HttpException(401, 'The user of this API token no longer exists.', ['WWW-Authenticate' => 'Bearer realm="api", error="invalid_token"']);
            }
            $request->setUser($user, $record);

            foreach ($abilities as $needed) {
                if (!ApiTokens::allows((array) $record['abilities'], (string) $needed)) {
                    throw new HttpException(403, 'This API token is not allowed to do that (needs "' . $needed . '").');
                }
            }
            return null;
        }

        $app = Application::instance();
        $guard = $app && $app->has('guard') ? $app->make('guard') : null;
        if ($guard instanceof Guard && $guard->check('private')) return null;

        throw new HttpException(401, 'Authentication is required. Send "Authorization: Bearer <token>".', ['WWW-Authenticate' => 'Bearer realm="api"']);
    }

    private function userFor(Application $app, string $id): ?array
    {
        $auth = $app->has('auth') ? $app->make('auth') : null;
        $provider = $auth instanceof Auth ? $auth->provider() : null;
        if (!$provider instanceof FindsUsersById) {
            throw new \RuntimeException('API tokens need a user provider that implements ' . FindsUsersById::class . '.');
        }
        return $provider->findById($id);
    }
}

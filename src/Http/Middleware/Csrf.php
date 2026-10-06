<?php

declare(strict_types=1);

namespace Cast\Http\Middleware;

use Cast\Contracts\Middleware;
use Cast\Core\Session;
use Cast\Http\HttpException;
use Cast\Http\Request;
use Cast\Http\Response;

/**
 * Checks the global CSRF token. The Router runs {@see verify()} for every POST, PUT, PATCH and DELETE
 * route, so it normally doesn't need to be added by hand. The token comes from the body (`_token`) or the
 * `X-CSRF-TOKEN` / `X-XSRF-TOKEN` header.
 */
final class Csrf implements Middleware
{
    public function handle(Request $request, mixed ...$args): ?Response
    {
        self::verify($request);
        return null;
    }

    public static function verify(Request $request): void
    {
        $given = $request->csrfToken();
        if ($given === '' || !hash_equals(Session::csrfToken(), $given)) {
            throw new HttpException(419, 'CSRF verification failed or the token has expired.');
        }
    }

    /** True when the request carries a valid token (no exception). */
    public static function valid(Request $request): bool
    {
        try {
            self::verify($request);
            return true;
        } catch (HttpException) {
            return false;
        }
    }
}

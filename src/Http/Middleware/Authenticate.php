<?php

declare(strict_types=1);

namespace Cast\Http\Middleware;

use Cast\App\Application;
use Cast\Contracts\Guard;
use Cast\Contracts\Middleware;
use Cast\Core\Config;
use Cast\Http\HttpException;
use Cast\Http\Request;
use Cast\Http\Response;

/**
 * `Router::middleware([Authenticate::class, 'private'], ...)`: logged-in users only.
 * `[Authenticate::class, 'auth']`: guests only (login page). `'public'`: anyone.
 */
final class Authenticate implements Middleware
{
    public function handle(Request $request, mixed ...$args): ?Response
    {
        $guardName = (string) ($args[0] ?? 'private');
        $app = Application::instance();
        $guard = $app && $app->has('guard') ? $app->make('guard') : null;
        if (!$guard instanceof Guard) {
            throw new \RuntimeException('No Guard is bound as "guard" in the container.');
        }
        if ($guard->check($guardName)) return null;

        if ($guardName === 'auth') {
            return Response::redirect(route((string) Config::get('auth.home_path', '/')));
        }
        if ($request->expectsJson()) {
            throw new HttpException(401, 'Your session has expired. Please log in again.');
        }
        return Response::redirect(route((string) Config::get('auth.login_path', '/login')));
    }
}

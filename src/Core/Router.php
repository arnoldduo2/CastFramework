<?php

declare(strict_types=1);

namespace Cast\Core;

use Cast\App\Application;
use Cast\Contracts\Guard;
use Cast\Contracts\Middleware as MiddlewareContract;
use Cast\Http\HttpException;
use Cast\Http\Middleware\Csrf;
use Cast\Http\Request;
use Cast\Http\Response;
use Closure;
use InvalidArgumentException;
use JsonSerializable;

/**
 * Route registration and dispatch.
 *
 *   Router::middleware([Authenticate::class, 'private'], function () {
 *       Router::group('/accounting', function () {
 *           Router::get('/', AccountingController::class);               // index()
 *           Router::get('/assets/{id}', [AssetsController::class, 'show']);
 *           Router::post('/assets/save', [AssetsController::class, 'save'])->middleware(['create-asset']);
 *           Router::put('/assets/{id}', [AssetsController::class, 'update']);
 *           Router::delete('/assets/{id}', [AssetsController::class, 'destroy']);
 *       });
 *   });
 *
 * A middleware spec is `[MiddlewareClass::class, ...$args]`; it runs `(new Class)->handle($request, ...$args)`.
 * A handler is a closure, `[Class::class, 'method']`, `'Class::method'`, or `Class::class` (calls `index`).
 */
final class Router
{
    private const STATE_CHANGING = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /** @var list<Route> */
    private static array $routes = [];
    private static string $prefix = '';
    /** @var list<array> */
    private static array $middleware = [];
    private static mixed $notFound = null;
    /** @var list<string> */
    private static array $csrfExempt = [];

    // ----------------------------------------------------------- registration

    public static function get(string $path, mixed $handler): Route
    {
        return self::add('GET', $path, $handler);
    }

    public static function post(string $path, mixed $handler): Route
    {
        return self::add('POST', $path, $handler);
    }

    public static function put(string $path, mixed $handler): Route
    {
        return self::add('PUT', $path, $handler);
    }

    public static function patch(string $path, mixed $handler): Route
    {
        return self::add('PATCH', $path, $handler);
    }

    public static function delete(string $path, mixed $handler): Route
    {
        return self::add('DELETE', $path, $handler);
    }

    /** @param list<string> $methods */
    public static function match(array $methods, string $path, mixed $handler): Route
    {
        $route = null;
        foreach ($methods as $method) $route = self::add(strtoupper($method), $path, $handler);
        return $route ?? throw new InvalidArgumentException('Router::match() needs at least one method.');
    }

    public static function any(string $path, mixed $handler): Route
    {
        return self::match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], $path, $handler);
    }

    /** Routes registered inside share a path prefix. */
    public static function group(string $prefix, callable $callback): void
    {
        $previous = self::$prefix;
        self::$prefix = $previous . '/' . trim($prefix, '/');
        try {
            $callback(self::class);
        } finally {
            self::$prefix = $previous;
        }
    }

    /** Routes registered inside run this middleware first: `[Class::class, ...$args]`. */
    public static function middleware(array $spec, callable $callback): void
    {
        $previous = self::$middleware;
        self::$middleware[] = $spec;
        try {
            $callback(self::class);
        } finally {
            self::$middleware = $previous;
        }
    }

    /** Handler for unmatched GET routes (receives the request input array). */
    public static function _404(callable $handler): void
    {
        self::$notFound = $handler;
    }

    /** Exempt an exact path from the central CSRF check. */
    public static function exemptCsrf(string $path): void
    {
        self::$csrfExempt[] = '/' . trim($path, '/');
    }

    /** @return list<Route> */
    public static function getRoutes(): array
    {
        return self::$routes;
    }

    public static function flush(): void
    {
        self::$routes = [];
        self::$prefix = '';
        self::$middleware = [];
        self::$notFound = null;
        self::$csrfExempt = [];
    }

    private static function add(string $method, string $path, mixed $handler): Route
    {
        $full = '/' . trim(self::$prefix . '/' . trim($path, '/'), '/');
        $route = new Route($method, $full, $handler, self::$middleware);
        self::$routes[] = $route;
        return $route;
    }

    // --------------------------------------------------------------- dispatch

    public static function dispatch(Request $request): Response
    {
        $method = $request->method();
        $lookup = $method === 'HEAD' ? 'GET' : $method;
        $path = $request->path();

        $route = null;
        $params = [];
        $allowed = [];
        foreach (self::$routes as $candidate) {
            $found = $candidate->match($path);
            if ($found === null) continue;
            if ($candidate->method === $lookup) {
                // an exact (static) path beats a parameterised one registered earlier
                if ($route === null || !str_contains($candidate->path, '{')) {
                    $route = $candidate;
                    $params = $found;
                    if (!str_contains($candidate->path, '{')) break;
                }
            }
            $allowed[$candidate->method] = true;
        }

        if ($route === null) {
            if ($allowed) {
                throw new HttpException(405, '', ['Allow' => implode(', ', array_keys($allowed))]);
            }
            if ($lookup === 'GET' && self::$notFound !== null) {
                return self::toResponse(self::invoke(self::$notFound, [$request->all()]), 404);
            }
            throw new HttpException(404);
        }

        $request->setRouteParams($params);

        foreach ([...$route->middleware, ...$route->extraMiddleware] as $spec) {
            $response = self::runMiddleware($spec, $request);
            if ($response !== null) return $response;
        }

        if ($route->csrf && in_array($method, self::STATE_CHANGING, true) && !in_array($path, self::$csrfExempt, true) && !self::csrfNotNeeded($request)) {
            Csrf::verify($request);
        }

        if ($route->permissions) {
            $app = Application::instance();
            $guard = $app && $app->has('guard') ? $app->make('guard') : null;
            if (!$guard instanceof Guard || !$guard->can($route->permissions)) {
                throw new HttpException(403, 'You do not have the required permission: ' . implode(', ', $route->permissions));
            }
        }

        return self::toResponse(self::invoke($route->handler, [...array_values($params), $request->all()]));
    }

    /**
     * CSRF protects requests that carry ambient credentials (the session cookie). An API request authenticated by a
     * bearer token has none, and an API request with no session cookie has nothing to forge: neither needs the token.
     */
    private static function csrfNotNeeded(Request $request): bool
    {
        if (!$request->isApi()) return false;
        return $request->usesToken() || $request->cookie((string) Config::get('session.name', 'cast_session')) === null;
    }

    private static function runMiddleware(array $spec, Request $request): ?Response
    {
        $class = array_shift($spec);
        if (!is_string($class) || !is_a($class, MiddlewareContract::class, true)) {
            throw new InvalidArgumentException('Middleware must be a class implementing ' . MiddlewareContract::class . '.');
        }
        return (new $class())->handle($request, ...$spec);
    }

    /** Run a handler, capturing anything it echoes. @return array{mixed, string} */
    private static function invoke(mixed $handler, array $args): array
    {
        if (is_string($handler) && !is_callable($handler)) {
            $handler = str_contains($handler, '::') ? explode('::', $handler, 2) : [$handler, 'index'];
        }
        if (is_array($handler) && is_string($handler[0] ?? null)) {
            $class = $handler[0];
            $handler = [new $class(), $handler[1] ?? 'index'];
        }
        if (!is_callable($handler)) {
            throw new InvalidArgumentException('Route handler is not callable.');
        }

        $level = ob_get_level();
        ob_start();
        try {
            $result = $handler(...$args);
        } catch (\Throwable $e) {
            while (ob_get_level() > $level) ob_end_clean();
            throw $e;
        }
        return [$result, (string) ob_get_clean()];
    }

    /** @param array{mixed, string} $outcome */
    private static function toResponse(array $outcome, ?int $status = null): Response
    {
        [$result, $buffer] = $outcome;

        if ($result instanceof Response) {
            $response = $result;
        } elseif (is_array($result) || $result instanceof JsonSerializable) {
            $response = Response::json($result);
        } elseif (is_string($result)) {
            $response = Response::html($buffer . $result);
        } else {
            // handlers that echo (and return null/true) keep working
            $json = $buffer !== '' && in_array($buffer[0], ['{', '['], true) && json_decode($buffer) !== null;
            $response = $json ? new Response($buffer, 200, ['Content-Type' => 'application/json; charset=utf-8']) : Response::html($buffer);
        }
        return $status !== null ? $response->status($status) : $response;
    }
}

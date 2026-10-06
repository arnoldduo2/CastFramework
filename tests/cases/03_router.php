<?php

declare(strict_types=1);

use Cast\Contracts\Guard;
use Cast\Contracts\Middleware;
use Cast\Core\Router;
use Cast\Http\HttpException;
use Cast\Http\Request;
use Cast\Http\Response;

class RouterTestController
{
    public function index(): string { return 'index'; }
    public function show(string $id): string { return "show:$id"; }
    public function legacy(): void { echo '{"echoed":true}'; }
    public function args(string $a, array $all): array { return ['a' => $a, 'seen' => array_keys($all)]; }
}

class RouterTestMiddleware implements Middleware
{
    public static array $log = [];
    public function handle(Request $request, mixed ...$args): ?Response
    {
        self::$log[] = 'mw:' . implode(',', $args);
        return ($args[0] ?? '') === 'stop' ? Response::html('stopped', 202) : null;
    }
}

function route_app(): void
{
    boot_app();
    RouterTestMiddleware::$log = [];
    $_SESSION['_csrf_token'] = 'tok';
}

function dispatch(string $method, string $uri, array $body = [], bool $token = true): Response
{
    $server = $token ? ['HTTP_X_CSRF_TOKEN' => 'tok'] : [];
    return Router::dispatch(req($method, $uri, $body, $server));
}

test('Router: GET/POST/PUT/PATCH/DELETE routes, params and groups', function () {
    route_app();
    Router::get('/a', fn() => 'get-a');
    Router::post('/a', fn() => 'post-a');
    Router::put('/a/{id}', fn(string $id) => "put-$id");
    Router::patch('/a/{id}', fn(string $id) => "patch-$id");
    Router::delete('/a/{id}', fn(string $id) => "delete-$id");
    Router::group('/admin', function () {
        Router::get('/users/{uid}/posts/{pid}', fn($uid, $pid) => "u$uid-p$pid");
        Router::group('/deep', fn() => Router::get('/x', fn() => 'deep-x'));
    });

    eq('get-a', dispatch('GET', '/a')->body());
    eq('post-a', dispatch('POST', '/a')->body());
    eq('put-5', dispatch('PUT', '/a/5')->body());
    eq('patch-6', dispatch('PATCH', '/a/6')->body());
    eq('delete-7', dispatch('DELETE', '/a/7')->body());
    eq('u3-p9', dispatch('GET', '/admin/users/3/posts/9')->body());
    eq('deep-x', dispatch('GET', '/admin/deep/x')->body());
    eq('get-a', dispatch('HEAD', '/a')->body(), 'HEAD uses the GET route');
});

test('Router: match(), any(), and a method override on a form POST', function () {
    route_app();
    Router::match(['GET', 'POST'], '/both', fn() => 'both');
    Router::any('/everything', fn() => 'any');
    Router::delete('/thing/{id}', fn($id) => "deleted-$id");

    eq('both', dispatch('GET', '/both')->body());
    eq('both', dispatch('POST', '/both')->body());
    foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $m) eq('any', dispatch($m, '/everything')->body(), $m);

    $request = new Request('POST', '/thing/4', [], '', ['HTTP_X_CSRF_TOKEN' => 'tok'], [], [], ['_method' => 'DELETE']);
    eq('deleted-4', Router::dispatch($request)->body(), 'POST + _method=DELETE');
});

test('Router: unknown path is 404, wrong verb is 405 with an Allow header', function () {
    route_app();
    Router::get('/only-get', fn() => 'x');
    Router::post('/only-get', fn() => 'x');
    Router::get('/read/{id}', fn() => 'x');

    $e = throws(HttpException::class, fn() => dispatch('GET', '/missing'));
    eq(404, $e->statusCode());

    $e = throws(HttpException::class, fn() => dispatch('DELETE', '/only-get'));
    eq(405, $e->statusCode());
    eq(['Allow' => 'GET, POST'], $e->headers());

    $e = throws(HttpException::class, fn() => dispatch('PUT', '/read/5'));
    eq(405, $e->statusCode(), 'also for parameterised paths');
});

test('Router: an exact path beats a parameterised one registered before it', function () {
    route_app();
    Router::get('/items/{id}', fn($id) => "item-$id");
    Router::get('/items/new', fn() => 'new-form');
    eq('new-form', dispatch('GET', '/items/new')->body());
    eq('item-9', dispatch('GET', '/items/9')->body());
});

test('Router: handler forms: closure, [Class, method], Class (index), "Class::method", and route params', function () {
    route_app();
    Router::get('/c1', [RouterTestController::class, 'show']);
    Router::get('/c2', RouterTestController::class);
    Router::get('/c3', 'RouterTestController::index');
    Router::get('/c4/{id}', [RouterTestController::class, 'show']);
    Router::get('/c5/{a}', [RouterTestController::class, 'args']);

    eq('index', dispatch('GET', '/c2')->body());
    eq('index', dispatch('GET', '/c3')->body());
    eq('show:12', dispatch('GET', '/c4/12')->body());
    eq(['a' => 'x', 'seen' => ['q']], Router::dispatch(req('GET', '/c5/x?q=1', [], [], ['q' => '1']))->data(), 'route params first, then all input');
    throws(TypeError::class, fn() => dispatch('GET', '/c1'), 'array given');
});

test('Router: return values: Response, array (JSON), string (HTML), echoed JSON/HTML', function () {
    route_app();
    Router::get('/r', fn() => Response::error('x', 418));
    Router::get('/arr', fn() => ['a' => 1]);
    Router::get('/str', fn() => '<b>x</b>');
    Router::get('/echo-json', [RouterTestController::class, 'legacy']);
    Router::get('/echo-html', function () { echo '<i>hi</i>'; });

    eq(418, dispatch('GET', '/r')->statusCode());
    eq(['a' => 1], dispatch('GET', '/arr')->data());
    has('text/html', (string) dispatch('GET', '/str')->getHeader('Content-Type'));
    $json = dispatch('GET', '/echo-json');
    ok($json->isJson() && $json->data() === ['echoed' => true], 'echoed JSON is detected');
    $html = dispatch('GET', '/echo-html');
    eq('<i>hi</i>', $html->body());
    ok(!$html->isJson());
});

test('Router: group middleware runs in order with its args, and can short-circuit', function () {
    route_app();
    Router::middleware([RouterTestMiddleware::class, 'outer'], function () {
        Router::get('/m1', fn() => 'handler');
        Router::middleware([RouterTestMiddleware::class, 'inner', 'extra'], function () {
            Router::get('/m2', fn() => 'handler');
        });
        Router::middleware([RouterTestMiddleware::class, 'stop'], fn() => Router::get('/m3', fn() => 'never'));
    });
    Router::get('/free', fn() => 'free');

    eq('handler', dispatch('GET', '/m1')->body());
    eq(['mw:outer'], RouterTestMiddleware::$log);

    RouterTestMiddleware::$log = [];
    dispatch('GET', '/m2');
    eq(['mw:outer', 'mw:inner,extra'], RouterTestMiddleware::$log);

    $r = dispatch('GET', '/m3');
    eq(202, $r->statusCode());
    eq('stopped', $r->body());

    RouterTestMiddleware::$log = [];
    dispatch('GET', '/free');
    eq([], RouterTestMiddleware::$log, 'routes outside the group are untouched');

    Router::get('/solo', fn() => 'solo')->use([RouterTestMiddleware::class, 'route-level']);
    dispatch('GET', '/solo');
    eq(['mw:route-level'], RouterTestMiddleware::$log);
});

test('Router: a non-Middleware class in a spec is rejected', function () {
    route_app();
    Router::middleware([stdClass::class], fn() => Router::get('/bad', fn() => 'x'));
    throws(InvalidArgumentException::class, fn() => dispatch('GET', '/bad'), 'implementing');
});

test('Router: ->middleware([slugs]) checks permissions through the bound Guard', function () {
    route_app();
    Router::get('/secret', fn() => 'secret')->middleware(['view-secret', 'admin']);
    Router::post('/secret', fn() => 'posted')->middleware(['edit-secret']);

    $guard = new class implements Guard {
        public array $granted = [];
        public function check(string $guard): bool { return true; }
        public function can(string|array $permission): bool { return (bool) array_intersect((array) $permission, $this->granted); }
        public function user(): ?array { return ['id' => 1]; }
    };
    app()->set('guard', $guard);

    $e = throws(HttpException::class, fn() => dispatch('GET', '/secret'));
    eq(403, $e->statusCode());
    has('view-secret, admin', $e->getMessage());

    $guard->granted = ['admin'];
    eq('secret', dispatch('GET', '/secret')->body(), 'any one slug is enough');
    throws(HttpException::class, fn() => dispatch('POST', '/secret'), 'required permission');
});

test('Router: _404 handler serves unmatched GET routes with a 404 status', function () {
    route_app();
    Router::get('/real', fn() => 'real');
    Router::_404(fn(array $all) => 'custom-404');
    $r = dispatch('GET', '/ghost');
    eq(404, $r->statusCode());
    eq('custom-404', $r->body());
    throws(HttpException::class, fn() => dispatch('POST', '/ghost'), '', 'POST still throws');
});

test('Router: a failing handler leaves no output buffers open', function () {
    route_app();
    Router::get('/boom', function () { echo 'partial'; throw new LogicException('boom'); });
    $level = ob_get_level();
    throws(LogicException::class, fn() => dispatch('GET', '/boom'));
    eq($level, ob_get_level());
});

test('Router: flush() clears routes and getRoutes() lists them', function () {
    route_app();
    Router::get('/a', fn() => 1);
    Router::post('/b', fn() => 1);
    eq(2, count(Router::getRoutes()));
    eq(['GET', 'POST'], array_map(fn($r) => $r->method, Router::getRoutes()));
    Router::flush();
    eq(0, count(Router::getRoutes()));
});

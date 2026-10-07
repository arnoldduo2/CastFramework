<?php

declare(strict_types=1);

use Cast\App\Application;
use Cast\Core\Router;
use Cast\Core\Session;
use Cast\Http\Middleware\Throttle;
use Cast\Http\Request;
use Cast\Services\ApiTokens;
use Cast\Services\ApiTokenSchema;
use Cast\Services\DatabaseTokenStore;

/** An in-memory token store, to test the service on its own. */
class MemoryTokenStore implements \Cast\Contracts\TokenStore
{
    public array $rows = [];
    public function create(array $record): void { $this->rows[$record['id']] = $record; }
    public function find(string $id): ?array { return $this->rows[$id] ?? null; }
    public function touch(string $id, int $time): void { $this->rows[$id]['last_used_at'] = $time; }
    public function revoke(string $id, int $time): bool
    {
        if (!isset($this->rows[$id]) || $this->rows[$id]['revoked_at']) return false;
        $this->rows[$id]['revoked_at'] = $time;
        return true;
    }
    public function revokeAllFor(string|int $userId, int $time): int
    {
        $n = 0;
        foreach ($this->rows as $id => $row) if ($row['user_id'] === (string) $userId && $this->revoke($id, $time)) $n++;
        return $n;
    }
}

/** A user provider that can also find users by id. */
final class ApiUsers implements \Cast\Contracts\UserProvider, \Cast\Contracts\FindsUsersById
{
    public const USERS = [
        1 => ['id' => 1, 'email' => 'ann@example.com', 'permissions' => ['manage']],
        2 => ['id' => 2, 'email' => 'bob@example.com', 'permissions' => []],
    ];
    public function findByCredentials(string $identifier): ?array
    {
        foreach (self::USERS as $u) if ($u['email'] === $identifier) return $u + ['password' => password_hash('secret', PASSWORD_DEFAULT)];
        return null;
    }
    public function onLogin(array $user): void {}
    public function passwordKey(): string { return 'password'; }
    public function findById(string|int $id): ?array { return self::USERS[(int) $id] ?? null; }
}

function api_app(array $configs = [], bool $db = true): Application
{
    $files = [
        'routes/api.php' => <<<'PHP'
<?php
use Cast\Core\Router;
use Cast\Http\Middleware\ApiAuth;
use Cast\Http\Middleware\Throttle;
use Cast\Http\Response;

Router::get('/ping', fn() => ['pong' => true]);
Router::post('/auth/token', function () {
    $user = app('auth')->verify(request()->input('email', ''), request()->input('password', ''));
    if (!$user) return Response::error('Bad credentials', 401);
    $t = app('tokens')->issue($user['id'], 'test', (array) request()->input('abilities', ['*']), request()->input('ttl'));
    return Response::success('', $t, 201);
});
Router::middleware([ApiAuth::class], function () {
    Router::get('/me', fn() => Response::success('', ['user' => request()->user() ?? app('guard')->user()]));
    Router::post('/things', fn() => Response::success('made', request()->all(), 201));
    Router::delete('/things/{id}', fn($id) => Response::success('gone ' . $id))->middleware(['manage']);
    Router::middleware([ApiAuth::class, 'things:write'], fn() => Router::put('/things/{id}', fn($id) => Response::success('updated ' . $id)));
});
Router::get('/limited', fn() => 'ok')->use([Throttle::class, 3, 1]);
Router::get('/boom', function () { throw new RuntimeException('internal secret'); });
Router::post('/validate', fn() => request()->validate(['name' => 'required|min:3']));
PHP,
        'routes/web.php' => "<?php\nuse Cast\\Core\\Router;\nRouter::get('/', fn() => 'home');\nRouter::post('/form', fn() => 'posted');\n",
    ];
    foreach ($configs as $name => $values) $files["config/$name.php"] = '<?php return ' . var_export($values, true) . ';';
    $app = boot_app($files);
    $app->singleton('auth', fn() => new \Cast\Services\Auth(new ApiUsers()));
    if ($db) {
        $pdo = sqlite(ApiTokenSchema::sql('sqlite'));
    }
    return $app;
}

function bearer(string $method, string $uri, string $token, array $body = [], array $server = []): Request
{
    return req($method, $uri, $body, ['HTTP_AUTHORIZATION' => "Bearer $token"] + $server);
}

function issue_token(array $abilities = ['*'], int $user = 1, ?int $ttl = null): array
{
    return app('tokens')->issue($user, 'test', $abilities, $ttl);
}

test('ApiTokens: issue returns id|secret, stores only a hash, and authenticates', function () {
    $store = new MemoryTokenStore();
    $tokens = new ApiTokens($store);
    $issued = $tokens->issue(7, 'phone', ['items:read'], 60);

    preg_match('/^([0-9a-f]{16})\|([A-Za-z0-9_-]{40})$/', $issued['token'], $m) || throw new AssertionError('token format: ' . $issued['token']);
    $row = $store->rows[$m[1]];
    eq(hash('sha256', $m[2]), $row['token_hash']);
    lacks($m[2], json_encode($row), 'the secret is not stored');
    eq(['items:read'], $row['abilities']);

    $record = $tokens->authenticate($issued['token']);
    eq('7', $record['user_id']);
    eq(null, $tokens->authenticate($m[1] . '|wrong' . substr($m[2], 5)), 'wrong secret');
    eq(null, $tokens->authenticate('nonsense'));
    eq(null, $tokens->authenticate('ffffffffffffffff|' . $m[2]), 'unknown id');
    eq(null, $tokens->authenticate('|' . $m[2]));
});

test('ApiTokens: expiry, revocation and last-use bookkeeping', function () {
    $now = 1000;
    $store = new MemoryTokenStore();
    $tokens = new ApiTokens($store, function () use (&$now) { return $now; });

    $a = $tokens->issue(1, 'a', ['*'], 100);
    $b = $tokens->issue(1, 'b');
    $c = $tokens->issue(2, 'c');
    eq(1100, $a['expires_at']);
    eq(null, $b['expires_at']);

    ok($tokens->authenticate($a['token']) !== null);
    eq(1000, $store->rows[$a['id']]['last_used_at'], 'last use recorded');
    $now = 1030;
    $tokens->authenticate($a['token']);
    eq(1000, $store->rows[$a['id']]['last_used_at'], 'at most once a minute');
    $now = 1100;
    eq(null, $tokens->authenticate($a['token']), 'expired');

    ok($tokens->revoke($b['id']));
    ok(!$tokens->revoke($b['id']), 'already revoked');
    eq(null, $tokens->authenticate($b['token']), 'revoked');
    eq(1, $tokens->revokeAllFor(1), 'the expired token was never revoked; b already was');
    eq(1, $tokens->revokeAllFor(2));
    eq(null, $tokens->authenticate($c['token']));
});

test('ApiTokens: abilities allow exact names, wildcards and *', function () {
    ok(ApiTokens::allows(['*'], 'anything:at-all'));
    ok(ApiTokens::allows(['items:read'], 'items:read'));
    ok(!ApiTokens::allows(['items:read'], 'items:write'));
    ok(ApiTokens::allows(['items:*'], 'items:write'));
    ok(!ApiTokens::allows(['items:*'], 'orders:write'));
    ok(!ApiTokens::allows(['items:*'], 'itemsx:write'), 'a group wildcard needs the colon');
    ok(!ApiTokens::allows([], 'x'));
});

test('DatabaseTokenStore: keeps tokens in the api_tokens table (all drivers share the schema)', function () {
    sqlite(ApiTokenSchema::sql('sqlite'));
    $tokens = new ApiTokens(new DatabaseTokenStore());
    $issued = $tokens->issue('u-9', 'db', ['a:b']);
    $row = \Cast\Core\QueryBuilder::table('api_tokens')->where('id', $issued['id'])->first();
    eq('u-9', $row['user_id']);
    has(hash('sha256', explode('|', $issued['token'])[1]), $row['token_hash']);
    eq(['a:b'], (new DatabaseTokenStore())->find($issued['id'])['abilities']);
    ok($tokens->authenticate($issued['token']) !== null);
    ok($tokens->revoke($issued['id']));
    eq(null, $tokens->authenticate($issued['token']));
    ok(str_contains(ApiTokenSchema::sql('mysql'), 'ENGINE=InnoDB'));
    ok(str_contains(ApiTokenSchema::sql('pgsql'), 'BIGINT'));
    throws(InvalidArgumentException::class, fn() => ApiTokenSchema::sql('mysql', 'x; DROP TABLE y'));
});

test('API: routes in routes/api.php are served under /api and answer JSON', function () {
    api_app();
    $r = handle(new Request('GET', '/api/ping'));
    eq(200, $r->statusCode());
    ok($r->isJson());
    eq(['pong' => true], json_decode($r->body(), true));
    eq(404, handle(new Request('GET', '/ping'))->statusCode(), 'not at the root');
    eq('home', handle(new Request('GET', '/'))->body(), 'web routes still work');
});

test('API: the prefix is configurable', function () {
    api_app(['api' => ['prefix' => '/v1']]);
    eq(200, handle(new Request('GET', '/v1/ping'))->statusCode());
    eq(404, handle(new Request('GET', '/api/ping'))->statusCode());
});

test('API: failures are always JSON, never HTML or a redirect (404, 405, 401, 422, 500)', function () {
    api_app();

    $r = handle(new Request('GET', '/api/nope'));
    eq(404, $r->statusCode());
    ok($r->isJson());
    eq('error', json_decode($r->body(), true)['status']);

    $r = handle(new Request('DELETE', '/api/ping'));
    eq(405, $r->statusCode());
    eq('GET', $r->getHeader('Allow'), 'Allow lists the verbs that exist');
    ok($r->isJson());

    $r = handle(new Request('GET', '/api/me'));
    eq(401, $r->statusCode());
    ok($r->isJson());
    eq(null, $r->getHeader('Location'), 'no redirect to a login page');
    has('Bearer', $r->getHeader('WWW-Authenticate') ?? '');

    $r = handle(req('POST', '/api/validate', ['name' => 'x']));
    eq(422, $r->statusCode());
    $json = json_decode($r->body(), true);
    eq('error', $json['status']);
    ok(isset($json['data']['errors']['name']));

    $r = handle(new Request('GET', '/api/boom'));
    eq(500, $r->statusCode());
    ok($r->isJson());
    has('internal secret', $r->body(), 'debug on in tests');
});

test('API: a 500 does not leak the exception message when debug is off', function () {
    api_app(['app' => ['debug' => false]]);
    \Cast\Core\Config::set('app.debug', false);
    $body = handle(new Request('GET', '/api/boom'))->body();
    lacks('internal secret', $body);
    has('Something went wrong', $body);
});

test('API: a bearer token authenticates; the user is on the request and in the guard', function () {
    api_app();
    $t = issue_token();
    $r = handle(bearer('GET', '/api/me', $t['token']));
    eq(200, $r->statusCode());
    eq('ann@example.com', json_decode($r->body(), true)['data']['user']['email']);
});

test('API: bad, revoked and expired tokens are 401, and never fall back to the session', function () {
    api_app();
    $t = issue_token();
    eq(401, handle(bearer('GET', '/api/me', $t['token'] . 'x'))->statusCode(), 'tampered');
    eq(401, handle(bearer('GET', '/api/me', 'garbage'))->statusCode());

    app('tokens')->revoke($t['id']);
    $r = handle(bearer('GET', '/api/me', $t['token']));
    eq(401, $r->statusCode(), 'revoked');
    has('invalid_token', $r->getHeader('WWW-Authenticate') ?? '');

    // a logged-in session does not rescue a bad token
    Session::set('login', ApiUsers::USERS[1]);
    eq(401, handle(bearer('GET', '/api/me', 'bad|token'))->statusCode());

    $short = issue_token(['*'], 1, 1);
    sleep(2);
    eq(401, handle(bearer('GET', '/api/me', $short['token']))->statusCode(), 'expired');
});

test('API: a token for a user that no longer exists is rejected', function () {
    api_app();
    $t = issue_token(['*'], 99);
    $r = handle(bearer('GET', '/api/me', $t['token']));
    eq(401, $r->statusCode());
    has('no longer exists', $r->body());
});

test('API: token abilities limit what a token can do (403)', function () {
    api_app();
    $reader = issue_token(['things:read']);
    $writer = issue_token(['things:*']);
    eq(403, handle(bearer('PUT', '/api/things/5', $reader['token'], ['x' => 1]))->statusCode());
    $r = handle(bearer('PUT', '/api/things/5', $writer['token'], ['x' => 1]));
    eq(200, $r->statusCode());
    eq('updated 5', json_decode($r->body(), true)['msg']);
});

test('API: permission slugs on a route use the token\'s user', function () {
    api_app();
    $ann = issue_token(['*'], 1);   // has "manage"
    $bob = issue_token(['*'], 2);   // has nothing
    eq(200, handle(bearer('DELETE', '/api/things/3', $ann['token']))->statusCode());
    eq(403, handle(bearer('DELETE', '/api/things/3', $bob['token']))->statusCode());
});

test('API CSRF: token requests and cookie-less requests need none; cookie-authenticated writes still do', function () {
    api_app();
    $t = issue_token();

    // bearer token: no CSRF token in the request
    eq(201, handle(bearer('POST', '/api/things', $t['token'], ['a' => 1]))->statusCode());

    // no session cookie at all: nothing to forge (this is how a mobile app or server calls the login route)
    eq(201, handle(req('POST', '/api/auth/token', ['email' => 'ann@example.com', 'password' => 'secret']))->statusCode());

    // a browser with the session cookie and a login session: the token is required
    Session::set('login', ApiUsers::USERS[1]);
    $cookie = ['cast_session' => 'abc'];
    $without = new Request('POST', '/api/things', [], '{"a":1}', ['CONTENT_TYPE' => 'application/json'], [], $cookie);
    $r = handle($without);
    eq(419, $r->statusCode());
    ok($r->isJson());

    $with = new Request('POST', '/api/things', [], '{"a":1}', ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => Session::csrfToken()], [], $cookie);
    eq(201, handle($with)->statusCode());
});

test('API CSRF: the web routes are untouched (a POST without a token is still a 419)', function () {
    api_app();
    eq(419, handle(new Request('POST', '/form'))->statusCode());
    eq(200, handle(form_post('/form'))->statusCode());
});

function form_post(string $uri): Request
{
    return new Request('POST', $uri, [], http_build_query(['_token' => Session::csrfToken()]), ['CONTENT_TYPE' => 'application/x-www-form-urlencoded']);
}

test('API: issuing a token through a login route works end to end', function () {
    api_app();
    $r = handle(req('POST', '/api/auth/token', ['email' => 'ann@example.com', 'password' => 'secret', 'abilities' => ['things:write']]));
    eq(201, $r->statusCode());
    $token = json_decode($r->body(), true)['data']['token'];
    eq(200, handle(bearer('PUT', '/api/things/1', $token))->statusCode());
    $other = json_decode(handle(req('POST', '/api/auth/token', ['email' => 'ann@example.com', 'password' => 'secret', 'abilities' => ['other:read']]))->body(), true)['data']['token'];
    eq(403, handle(bearer('PUT', '/api/things/1', $other))->statusCode(), 'a token without things:write');

    eq(401, handle(req('POST', '/api/auth/token', ['email' => 'ann@example.com', 'password' => 'nope']))->statusCode());
    ok(!isset($_SESSION['login']), 'verify() does not log anyone in');
});

test('Throttle: counts per caller, sends rate-limit headers, answers 429 with Retry-After', function () {
    api_app();
    $now = 5000;
    Throttle::$clock = function () use (&$now) { return $now; };

    $r = handle(new Request('GET', '/api/limited', [], '', ['REMOTE_ADDR' => '10.0.0.1']));
    eq(200, $r->statusCode());
    eq('3', $r->getHeader('X-RateLimit-Limit'));
    eq('2', $r->getHeader('X-RateLimit-Remaining'));
    eq((string) ($now + 60), $r->getHeader('X-RateLimit-Reset'));

    handle(new Request('GET', '/api/limited', [], '', ['REMOTE_ADDR' => '10.0.0.1']));
    $r = handle(new Request('GET', '/api/limited', [], '', ['REMOTE_ADDR' => '10.0.0.1']));
    eq('0', $r->getHeader('X-RateLimit-Remaining'));

    $now += 10;
    $r = handle(new Request('GET', '/api/limited', [], '', ['REMOTE_ADDR' => '10.0.0.1']));
    eq(429, $r->statusCode());
    ok($r->isJson());
    eq('50', $r->getHeader('Retry-After'));
    eq('0', $r->getHeader('X-RateLimit-Remaining'));

    // another address has its own allowance
    eq(200, handle(new Request('GET', '/api/limited', [], '', ['REMOTE_ADDR' => '10.0.0.2']))->statusCode());

    // the window ends and counting starts again
    $now += 55;
    eq(200, handle(new Request('GET', '/api/limited', [], '', ['REMOTE_ADDR' => '10.0.0.1']))->statusCode());
    Throttle::$clock = null;
});

test('Throttle: one counter per caller across routes; a third argument makes a separate counter', function () {
    api_app();
    Router::get('/api/also-limited', fn() => 'ok')->use([Throttle::class, 3, 1]);
    Router::get('/api/own-counter', fn() => 'ok')->use([Throttle::class, 3, 1, 'own']);
    Throttle::$clock = fn() => 4000;
    $server = ['REMOTE_ADDR' => '10.9.9.9'];
    handle(new Request('GET', '/api/limited', [], '', $server));
    handle(new Request('GET', '/api/also-limited', [], '', $server));
    $r = handle(new Request('GET', '/api/limited', [], '', $server));
    eq('0', $r->getHeader('X-RateLimit-Remaining'), 'three requests over two routes used the same counter');
    eq(429, handle(new Request('GET', '/api/also-limited', [], '', $server))->statusCode());
    eq(200, handle(new Request('GET', '/api/own-counter', [], '', $server))->statusCode(), 'its own counter');
    Throttle::$clock = null;
});

test('Throttle: a token is limited by its own id, not by the shared address', function () {
    api_app();
    Throttle::$clock = fn() => 9000;
    $a = issue_token();
    $b = issue_token();
    foreach ([1, 2, 3] as $i) handle(bearer('GET', '/api/limited', $a['token'], [], ['REMOTE_ADDR' => '10.0.0.9']));
    eq(429, handle(bearer('GET', '/api/limited', $a['token'], [], ['REMOTE_ADDR' => '10.0.0.9']))->statusCode());
    eq(200, handle(bearer('GET', '/api/limited', $b['token'], [], ['REMOTE_ADDR' => '10.0.0.9']))->statusCode());
    Throttle::$clock = null;
});

test('API CORS: preflight is answered for allowed origins, and rate-limit headers are exposed', function () {
    api_app(['cors' => ['allowed_origins' => ['https://front.example.com']]]);
    $bootstrap = new \Cast\Boot\Bootstrap(Application::instance());
    $pre = new Request('OPTIONS', '/api/items', [], '', ['HTTP_ORIGIN' => 'https://front.example.com', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'PUT']);
    $r = $bootstrap->handle($pre);
    eq(204, $r->statusCode());
    eq('https://front.example.com', $r->getHeader('Access-Control-Allow-Origin'));
    has('Authorization', $r->getHeader('Access-Control-Allow-Headers') ?? '');
    has('PATCH', $r->getHeader('Access-Control-Allow-Methods') ?? '');
    has('X-RateLimit-Remaining', $r->getHeader('Access-Control-Expose-Headers') ?? '');

    $other = $bootstrap->handle(new Request('OPTIONS', '/api/items', [], '', ['HTTP_ORIGIN' => 'https://evil.example.com']));
    eq(null, $other->getHeader('Access-Control-Allow-Origin'), 'unknown origins get no CORS headers');
});

test('API: api.middleware applies to every route in routes/api.php', function () {
    api_app(['api' => ['middleware' => [[Throttle::class, 2, 1]]]]);
    Throttle::$clock = fn() => 100;
    eq('2', handle(new Request('GET', '/api/ping', [], '', ['REMOTE_ADDR' => '1.1.1.1']))->getHeader('X-RateLimit-Limit'));
    handle(new Request('GET', '/api/ping', [], '', ['REMOTE_ADDR' => '1.1.1.1']));
    eq(429, handle(new Request('GET', '/api/ping', [], '', ['REMOTE_ADDR' => '1.1.1.1']))->statusCode());
    eq(200, handle(new Request('GET', '/', [], '', ['REMOTE_ADDR' => '1.1.1.1']))->statusCode(), 'web routes are not in the group');
    Throttle::$clock = null;
});

test('Request: bearerToken() reads the Authorization header', function () {
    eq('abc|def', (new Request('GET', '/', [], '', ['HTTP_AUTHORIZATION' => 'Bearer abc|def']))->bearerToken());
    eq('abc', (new Request('GET', '/', [], '', ['HTTP_AUTHORIZATION' => 'bearer   abc']))->bearerToken());
    eq(null, (new Request('GET', '/', [], '', ['HTTP_AUTHORIZATION' => 'Basic Zm9vOmJhcg==']))->bearerToken());
    eq(null, (new Request('GET'))->bearerToken());
    eq('x', (new Request('GET', '/', [], '', ['REDIRECT_HTTP_AUTHORIZATION' => 'Bearer x']))->bearerToken());
    ok((new Request('GET', '/api/x'))->isApi());
    ok((new Request('GET', '/api'))->isApi());
    ok(!(new Request('GET', '/apiary'))->isApi(), 'prefix must end at a path boundary');
});

test('API: nested ApiAuth checks the token once and only adds ability checks', function () {
    api_app();
    $lookups = 0;
    $store = new class($lookups) extends MemoryTokenStore {
        public function __construct(public int &$finds) {}
        public function find(string $id): ?array { $this->finds++; return parent::find($id); }
    };
    app()->singleton('tokens', fn() => new ApiTokens($store));
    $issued = app('tokens')->issue(1, 'nested', ['things:write']);
    $lookups = 0;
    eq(200, handle(bearer('PUT', '/api/things/4', $issued['token'], ['x' => 1]))->statusCode());
    eq(1, $lookups, 'the token record was loaded once, not once per ApiAuth in the group');
});

<?php

declare(strict_types=1);

use Cast\Core\Router;
use Cast\Http\Middleware\Throttle;
use Cast\Http\Request;
use Cast\Services\ApiTokenSchema;

function api_json(Request $request): array
{
    $r = handle($request);
    return ['code' => $r->statusCode(), 'json' => json_decode($r->body(), true), 'response' => $r];
}

function token_request(string $method, string $uri, string $token, array $body = []): Request
{
    return req($method, $uri, $body, ['HTTP_AUTHORIZATION' => "Bearer $token", 'HTTP_ACCEPT' => 'application/json']);
}

function starter_token(array $extra = []): string
{
    $r = api_json(req('POST', '/api/auth/token', ['email' => 'admin@example.com', 'password' => 'password'] + $extra));
    eq(201, $r['code']);
    return $r['json']['data']['token'];
}

test('Starter API: get a token with email and password (no session, no CSRF token)', function () {
    starter();
    $r = api_json(req('POST', '/api/auth/token', ['email' => 'admin@example.com', 'password' => 'password']));
    eq(201, $r['code']);
    eq('success', $r['json']['status']);
    eq('Bearer', $r['json']['data']['token_type']);
    ok(preg_match('/^[0-9a-f]{16}\|[A-Za-z0-9_-]{40}$/', $r['json']['data']['token']) === 1);
    eq('admin@example.com', $r['json']['data']['user']['email']);
    ok(!isset($_SESSION['login']), 'no login session was started');

    $bad = api_json(req('POST', '/api/auth/token', ['email' => 'admin@example.com', 'password' => 'nope']));
    eq(401, $bad['code']);
    eq('error', $bad['json']['status']);

    $invalid = api_json(req('POST', '/api/auth/token', ['email' => 'not-an-email']));
    eq(422, $invalid['code']);
    ok(isset($invalid['json']['data']['errors']['email']));
    ok(isset($invalid['json']['data']['errors']['password']));
});

test('Starter API: the login route is rate limited (10 a minute per address)', function () {
    starter();
    Throttle::$clock = fn() => 7000;
    $server = ['REMOTE_ADDR' => '203.0.113.5'];
    for ($i = 1; $i <= 10; $i++) {
        $r = handle(req('POST', '/api/auth/token', ['email' => 'admin@example.com', 'password' => 'wrong'], $server));
        eq(401, $r->statusCode(), "attempt $i");
    }
    $r = handle(req('POST', '/api/auth/token', ['email' => 'admin@example.com', 'password' => 'password'], $server));
    eq(429, $r->statusCode());
    ok($r->getHeader('Retry-After') !== null);
    Throttle::$clock = null;
});

test('Starter API: /api/me needs a token and returns the user', function () {
    starter();
    $guest = api_json(new Request('GET', '/api/me', [], '', ['HTTP_ACCEPT' => 'application/json']));
    eq(401, $guest['code']);

    $me = api_json(token_request('GET', '/api/me', starter_token()));
    eq(200, $me['code']);
    eq(['manage-items'], $me['json']['data']['user']['permissions']);
    lacks('password', json_encode($me['json']), 'no password hash in the response');
});

test('Starter API: items CRUD with a token, no CSRF token needed', function () {
    starter();
    $t = starter_token();

    $created = api_json(token_request('POST', '/api/items', $t, ['name' => 'Bolt', 'qty' => 10, 'price' => 1.5]));
    eq(201, $created['code']);
    $id = $created['json']['data']['item']['id'];
    eq('Bolt', $created['json']['data']['item']['name']);

    $one = api_json(token_request('GET', "/api/items/$id", $t));
    eq(200, $one['code']);
    eq(10, (int) $one['json']['data']['item']['qty']);

    $list = api_json(token_request('GET', '/api/items?per_page=5', $t));
    eq(1, $list['json']['data']['total']);
    eq(1, $list['json']['data']['last_page']);
    eq(5, $list['json']['data']['per_page']);

    $updated = api_json(token_request('PUT', "/api/items/$id", $t, ['name' => 'Bolt M8', 'qty' => 12, 'price' => 1.75]));
    eq(200, $updated['code']);
    eq('Bolt M8', $updated['json']['data']['item']['name']);

    $invalid = api_json(token_request('POST', '/api/items', $t, ['name' => 'x', 'qty' => -1, 'price' => 'abc']));
    eq(422, $invalid['code']);
    eq(['name', 'qty', 'price'], array_keys($invalid['json']['data']['errors']));

    eq(404, api_json(token_request('GET', '/api/items/9999', $t))['code']);
    eq(200, api_json(token_request('DELETE', "/api/items/$id", $t))['code']);
    eq(404, api_json(token_request('DELETE', "/api/items/$id", $t))['code']);
});

test('Starter API: abilities limit a token (items:read cannot write)', function () {
    starter();
    $read = starter_token(['abilities' => ['items:read']]);
    eq(200, api_json(token_request('GET', '/api/items', $read))['code']);
    $denied = api_json(token_request('POST', '/api/items', $read, ['name' => 'Nut', 'qty' => 1, 'price' => 1]));
    eq(403, $denied['code']);
    has('items:write', $denied['json']['msg']);
});

test('Starter API: DELETE /api/auth/token revokes the token that made the request', function () {
    starter();
    $t = starter_token();
    eq(200, api_json(token_request('DELETE', '/api/auth/token', $t))['code']);
    eq(401, api_json(token_request('GET', '/api/me', $t))['code'], 'the token is gone');
});

test('Starter API: the same endpoints work with the browser session (same-site clients) + CSRF token', function () {
    starter();
    login_admin();
    $get = api_json(new Request('GET', '/api/items', [], '', ['HTTP_ACCEPT' => 'application/json']));
    eq(200, $get['code'], 'session user');

    $cookie = ['cast_session' => 'x'];
    $noToken = new Request('POST', '/api/items', [], json_encode(['name' => 'Gear', 'qty' => 1, 'price' => 2]), ['CONTENT_TYPE' => 'application/json'], [], $cookie);
    eq(419, handle($noToken)->statusCode());
    $withToken = new Request('POST', '/api/items', [], json_encode(['name' => 'Gear', 'qty' => 1, 'price' => 2]), ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => \Cast\Core\Session::csrfToken()], [], $cookie);
    eq(201, handle($withToken)->statusCode(), 'a session user is not limited by token abilities');
});

test('Console: token:create prints a working token once, token:revoke revokes it, token:schema prints SQL', function () {
    $app = starter();
    [$code, $out] = cast(['token:create', 'admin@example.com', '--name=ci', '--abilities=items:read', '--days=1'], $app);
    eq(0, $code);
    has('Token created', $out);
    preg_match('/([0-9a-f]{16}\|[A-Za-z0-9_-]{40})/', $out, $m) || throw new AssertionError('no token in: ' . $out);
    eq(200, api_json(token_request('GET', '/api/items', $m[1]))['code']);
    $row = \Cast\Core\QueryBuilder::table('api_tokens')->where('name', 'ci')->first();
    ok($row['expires_at'] > time() + 80000, 'expires in about a day');

    [$code, $out] = cast(['token:revoke', $row['id']], $app);
    eq(0, $code);
    eq(401, api_json(token_request('GET', '/api/items', $m[1]))['code']);
    eq(1, cast(['token:revoke', $row['id']], $app)[0], 'already revoked');
    eq(1, cast(['token:create', 'nobody@example.com'], $app)[0], 'unknown user');
    eq(1, cast(['token:create'], $app)[0], 'needs a login');

    [, $sql] = cast(['token:schema'], $app);
    has('CREATE TABLE IF NOT EXISTS api_tokens', $sql);
});

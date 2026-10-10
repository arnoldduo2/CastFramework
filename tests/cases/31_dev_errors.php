<?php

declare(strict_types=1);

use Cast\Core\Config;
use Cast\Core\Router;
use Cast\Http\Request;

/** Server errors for Cast (SPA) and API clients: in development the JSON carries where it happened, in production nothing of that. */

function crashing_app(string $env = 'development', bool $debug = true): void
{
    boot_app(['.env' => "APP_NAME=Shop\nAPP_ENV=$env\nAPP_DEBUG=" . ($debug ? 'true' : 'false') . "\n"]);
    Router::get('/crash', function () {
        $items = ['qty' => 2];
        return $items['qty'] * $price;          // line 16: $price is not defined
    });
    Router::get('/api/crash', fn() => throw new RuntimeException('API boom'));
}

$handlerAvailable = class_exists(\Anode\ErrorHandler\Report::class);

test('dev errors: a Cast request that crashes gets the envelope with data.debug (code, stack), in development only', function () {
    crashing_app();
    set_error_handler(fn(int $no, string $msg, string $file, int $line) => throw new ErrorException($msg, 0, $no, $file, $line));
    try {
        $r = handle(new Request('GET', '/crash', [], '', ['HTTP_X_CAST_REQUEST' => '1']));
    } finally {
        restore_error_handler();
    }
    eq(500, $r->statusCode());
    $body = json_decode($r->body(), true);
    eq('error', $body['status']);
    has('Undefined variable $price', $body['msg']);
    eq('error', $body['data']['type']);
    $d = $body['data']['debug'];
    eq('ErrorException', $d['kind']);
    has('31_dev_errors.php', $d['file']);
    eq(16, $d['line']);
    ok(count($d['code']) >= 5, 'the code around the line');
    $failing = array_values(array_filter($d['code'], fn($l) => $l['error']));
    eq(1, count($failing));
    has('$items[\'qty\'] * $price', $failing[0]['text']);
    ok(count($d['trace']) >= 1 && isset($d['trace'][0]['file'], $d['trace'][0]['line']));
    ok(preg_match('/^[0-9a-f]{8}$/', $d['id']) === 1);
});

test('dev errors: an API client gets data.debug too; production sends none of it', function () {
    crashing_app();
    $r = handle(new Request('GET', '/api/crash', [], '', ['HTTP_ACCEPT' => 'application/json']));
    eq(500, $r->statusCode());
    $body = json_decode($r->body(), true);
    eq('API boom', $body['msg']);
    eq('RuntimeException', $body['data']['debug']['kind']);
    ok(!isset($body['data']['type']), 'an API answer has no Cast fields');

    foreach ([['production', false], ['production', true], ['development', false]] as [$env, $debug]) {
        crashing_app($env, $debug);
        $p = handle(new Request('GET', '/api/crash', [], '', ['HTTP_ACCEPT' => 'application/json']));
        $json = json_decode($p->body(), true);
        ok(!isset($json['data']['debug']), "no debug for env=$env debug=" . var_export($debug, true));
        if (!$debug) lacks('API boom', $p->body(), "the message is hidden for env=$env");
    }
});

test('dev errors: a Cast request in production has no debug and a generic message', function () {
    crashing_app('production', false);
    $r = handle(new Request('GET', '/api/crash', [], '', ['HTTP_X_CAST_REQUEST' => '1']));
    $body = json_decode($r->body(), true);
    ok(!isset($body['data']['debug']));
    lacks('API boom', $r->body());
    eq('error', $body['data']['type']);
});

test('dev errors: the full error page is served at /cast/error/<id> in development, 404 otherwise', function () use ($handlerAvailable) {
    if (!$handlerAvailable) return;                          // needs anode/error-handler 1.3
    crashing_app();
    $r = handle(new Request('GET', '/api/crash', [], '', ['HTTP_ACCEPT' => 'application/json']));
    $d = json_decode($r->body(), true)['data']['debug'];
    ok(is_string($d['url']) && str_ends_with($d['url'], '/cast/error/' . $d['id']), 'a link to the page');
    $page = handle(new Request('GET', '/cast/error/' . $d['id']));
    eq(500, $page->statusCode());
    has('API boom', $page->body());
    has('class="cl cl-error"', $page->body(), 'with the code');
    has('Open in editor', $page->body());
    eq(404, handle(new Request('GET', '/cast/error/deadbeef'))->statusCode(), 'an id that was never stored');
    eq(404, handle(new Request('GET', '/cast/error/../../.env'))->statusCode(), 'ids are 8 hex digits only');
    ok(is_file(app()->storagePath('framework/errors/' . $d['id'] . '.json')));
    // a front end on another port gets an absolute link
    $_SERVER['HTTP_HOST'] = 'localhost:8000';
    $abs = json_decode(handle(new Request('GET', '/api/crash', [], '', ['HTTP_ACCEPT' => 'application/json']))->body(), true)['data']['debug']['url'];
    unset($_SERVER['HTTP_HOST']);
    eq('http://localhost:8000/cast/error/' . $d['id'], $abs);

    Config::set('app.env', 'production');
    eq(404, handle(new Request('GET', '/cast/error/' . $d['id']))->statusCode(), 'never in production');
});

test('dev errors: only the last 25 reports are kept', function () use ($handlerAvailable) {
    if (!$handlerAvailable) return;
    crashing_app();
    for ($i = 0; $i < 30; $i++) {
        Router::get("/api/e$i", fn() => throw new RuntimeException("boom $i"));
        handle(new Request('GET', "/api/e$i", [], '', ['HTTP_ACCEPT' => 'application/json']));
    }
    eq(25, count(glob(app()->storagePath('framework/errors/*.json'))));
});

test('dev errors: the overlay script is served, escapes everything it shows, and the Cast client loads it only for debug envelopes', function () {
    $js = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Resources/error-overlay.js');
    ok(str_contains($js, 'attachShadow') && str_contains($js, 'textContent'), 'a shadow root, text only');
    ok(!str_contains($js, 'innerHTML'), 'nothing is inserted as HTML');
    ok(str_contains($js, 'javascript:'), 'javascript: links are refused');
    $client = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Resources/cast.module.js');
    has('res.data.debug', $client);
    has('error-overlay.js', $client);
    $app = boot_app(['.env' => "APP_NAME=Shop\n"], boot: false);
    $r = (new \Cast\Services\StaticResourceProvider($app))->serve(new Request('GET', '/cast/error-overlay.js'));
    eq(200, $r->statusCode());
});

<?php

declare(strict_types=1);

use Cast\Core\Config;
use Cast\Http\HttpException;
use Cast\Http\Request;
use Cast\Http\Response;

test('Request: query, JSON body and form body are parsed for every verb', function () {
    $get = new Request('GET', '/x?a=1&b[]=2', ['a' => '1', 'b' => ['2']]);
    eq('1', $get->query('a'));
    eq(['a' => '1', 'b' => ['2']], $get->query());
    eq('d', $get->query('zz', 'd'));
    eq([], $get->body());

    $put = new Request('PUT', '/x', [], '{"name":"Ann","tags":[1,2]}', ['CONTENT_TYPE' => 'application/json']);
    eq(['name' => 'Ann', 'tags' => [1, 2]], $put->body(), 'PUT json');

    $delete = new Request('DELETE', '/x', [], 'id=5&reason=old+one', ['CONTENT_TYPE' => 'application/x-www-form-urlencoded']);
    eq(['id' => '5', 'reason' => 'old one'], $delete->body(), 'DELETE form');

    $patch = new Request('PATCH', '/x', [], '{"a":1}', []);
    eq(['a' => 1], $patch->body(), 'JSON detected without a content type');

    $post = new Request('POST', '/x', [], '', [], [], [], ['title' => 'From $_POST']);
    eq(['title' => 'From $_POST'], $post->body(), 'multipart/form POST uses $_POST');

    eq([], (new Request('POST', '/x', [], '{broken', ['CONTENT_TYPE' => 'application/json']))->body(), 'invalid JSON => []');
});

test('Request: all(), input(), has(), filled(), only(), except()', function () {
    $r = new Request('POST', '/x', ['q' => 'search', 'a' => 'query'], '{"a":"body","n":null,"e":"","z":0}', ['CONTENT_TYPE' => 'application/json']);
    eq('body', $r->input('a'), 'body wins over query');
    eq('search', $r->input('q'));
    eq('dflt', $r->input('nope', 'dflt'));
    ok($r->has('n') && $r->has(['a', 'q']) && !$r->has(['a', 'missing']), 'has');
    ok($r->filled('a') && $r->filled('z') && !$r->filled('n') && !$r->filled('e') && !$r->filled(['a', 'e']), 'filled');
    eq(['a' => 'body', 'q' => 'search'], $r->only('a', 'q'));
    ok(!array_key_exists('a', $r->except('a')), 'except');
});

test('Request: _method override works on POST only', function () {
    $form = new Request('POST', '/x', [], '', [], [], [], ['_method' => 'delete']);
    eq('DELETE', $form->method());
    ok($form->isMethod('delete'));

    $json = new Request('POST', '/x', [], '{"_method":"PUT"}', ['CONTENT_TYPE' => 'application/json']);
    eq('PUT', $json->method());

    $header = new Request('POST', '/x', [], '', ['HTTP_X_HTTP_METHOD_OVERRIDE' => 'PATCH']);
    eq('PATCH', $header->method());

    eq('POST', (new Request('POST', '/x', [], '', [], [], [], ['_method' => 'TRACE']))->method(), 'unknown override ignored');
    eq('GET', (new Request('GET', '/x', ['_method' => 'DELETE']))->method(), 'GET cannot be overridden');
});

test('Request: path() strips the base path and normalises slashes', function () {
    boot_app(['config/app.php' => "<?php return ['base_path' => '/erp-app'];"], boot: false);
    eq('/dashboard', (new Request('GET', '/erp-app/dashboard?x=1'))->path());
    eq('/', (new Request('GET', '/erp-app'))->path());
    eq('/', (new Request('GET', '/erp-app/'))->path());
    eq('/other', (new Request('GET', '/other/'))->path(), 'not under the base path');
    eq('/erp-application', (new Request('GET', '/erp-application'))->path(), 'prefix must match a whole segment');
});

test('Request: headers, ip, ajax/json/expectsJson', function () {
    $r = new Request('GET', '/x', [], '', ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'text/html', 'CONTENT_TYPE' => 'text/plain', 'REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_CUSTOM_HEADER' => 'v']);
    eq('v', $r->header('X-Custom-Header'));
    eq('text/plain', $r->header('Content-Type'));
    eq('dflt', $r->header('Nope', 'dflt'));
    eq('10.0.0.5', $r->ip());
    ok($r->isAjax() && $r->expectsJson() && !$r->isJson());

    ok((new Request('GET', '/x', [], '', ['HTTP_ACCEPT' => 'application/json']))->expectsJson());
    ok((new Request('POST', '/x', [], '{}', ['CONTENT_TYPE' => 'application/json']))->isJson());
    ok(!(new Request('GET', '/x', [], '', ['HTTP_ACCEPT' => 'text/html']))->expectsJson());
});

test('Request: csrfToken() from the body or either header', function () {
    eq('b', (new Request('POST', '/x', [], '{"_token":"b"}', ['CONTENT_TYPE' => 'application/json']))->csrfToken());
    eq('h1', (new Request('POST', '/x', [], '', ['HTTP_X_CSRF_TOKEN' => 'h1']))->csrfToken());
    eq('h2', (new Request('POST', '/x', [], '', ['HTTP_X_XSRF_TOKEN' => 'h2']))->csrfToken());
    eq('', (new Request('POST', '/x'))->csrfToken());
});

test('Request: cast (SPA) headers', function () {
    $r = new Request('GET', '/x', [], '', ['HTTP_X_CAST_REQUEST' => '1', 'HTTP_X_CAST_TYPE' => 'Modal', 'HTTP_X_CAST_TARGET' => '#box', 'HTTP_X_CAST_GUARD' => 'private']);
    ok($r->isCast() && $r->expectsJson());
    eq('modal', $r->castType());
    eq('#box', $r->castTarget());
    eq('private', $r->castGuard());
    ok(!(new Request('GET', '/x'))->isCast());
    eq('partial', (new Request('GET', '/x', [], '', ['HTTP_X_CAST_TYPE' => 'weird']))->castType());
});

test('Request::postData() and getPost(): JSON by default, form on request, sanitiser from config', function () {
    boot_app(boot: false);
    $r = new Request('POST', '/x', [], '{"a":"  padded  ","n":{"b":" x "}}', ['CONTENT_TYPE' => 'application/json']);
    eq(['a' => 'padded', 'n' => ['b' => 'x']], $r->postData(), 'default sanitiser trims');
    eq([], (new Request('POST', '/x', [], 'a=1'))->postData(), 'form body is not JSON');
    eq(['a' => '1'], (new Request('POST', '/x', [], 'a=1'))->postData(true));
    eq([], (new Request('POST', '/x', [], ''))->postData());

    Config::set('request.sanitizer', fn(array $d) => array_map(fn($v) => is_string($v) ? strtoupper($v) : $v, $d));
    eq(['a' => 'X'], (new Request('POST', '/x', [], '{"a":"x"}'))->postData());

    Request::setCurrent(new Request('POST', '/x', [], '{"a":"y"}'));
    eq(['a' => 'Y'], getPost(), 'getPost() helper goes through the current request');
    eq(['a' => 'y'], Request::current()->body(), 'the request itself stays raw');
});

test('Response: JSON helpers follow the {status, msg, data} contract', function () {
    $ok = Response::success('Saved!', ['id' => 5]);
    eq(200, $ok->statusCode());
    eq(['status' => 'success', 'msg' => 'Saved!', 'data' => ['id' => 5]], $ok->data());
    eq('application/json; charset=utf-8', $ok->getHeader('Content-Type'));
    eq('{"status":"success","msg":"","data":{}}', Response::success()->body(), 'empty data is an object');

    $err = Response::error('Nope', 403);
    eq(403, $err->statusCode());
    eq(['status' => 'error', 'msg' => 'Nope'], $err->data());

    $invalid = Response::error('Bad', 422, ['email' => 'Required']);
    eq(['status' => 'error', 'msg' => 'Bad', 'data' => ['errors' => ['email' => 'Required']]], $invalid->data());
    ok($invalid->isJson());
});

test('Response: html, redirect, noContent, status/header chaining, headers are case-insensitive', function () {
    $html = Response::html('<p>x</p>', 201);
    eq(201, $html->statusCode());
    has('text/html', (string) $html->getHeader('content-type'));

    $redirect = Response::redirect('/login');
    eq(302, $redirect->statusCode());
    eq('/login', $redirect->getHeader('LOCATION'));
    eq(301, Response::redirect('/x', 301)->statusCode());
    eq(204, Response::noContent()->statusCode());

    $r = (new Response('b'))->status(418)->header('X-Foo', 'bar');
    eq(418, $r->statusCode());
    eq('bar', $r->getHeader('x-foo'));
    eq('b', $r->body());
    eq('c', $r->body('c')->body());
});

test('Response::send() outputs the body (and a file response streams the file)', function () {
    ob_start();
    Response::html('hello')->send();
    eq('hello', ob_get_clean());

    $file = app_dir(['a.css' => 'body{}']) . '/a.css';
    ob_start();
    Response::file($file, 'text/css')->send();
    eq('body{}', ob_get_clean());
    eq('6', Response::file($file, 'text/css')->getHeader('Content-Length'));
});

test('HttpException: status, default message, headers, view', function () {
    $e = new HttpException(404);
    eq(404, $e->statusCode());
    eq('Page Not Found', $e->getMessage());
    $e = new HttpException(405, '', ['Allow' => 'GET'], 'custom.view', ['k' => 1]);
    eq(['Allow' => 'GET'], $e->headers());
    eq('custom.view', $e->view);
    eq(['k' => 1], $e->data);
    eq('Custom', (new HttpException(500, 'Custom'))->getMessage());
});

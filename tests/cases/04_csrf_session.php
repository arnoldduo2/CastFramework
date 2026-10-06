<?php

declare(strict_types=1);

use Cast\Core\Router;
use Cast\Core\Session;
use Cast\Http\HttpException;
use Cast\Http\Middleware\Csrf;
use Cast\Http\Request;

function csrf_app(): string
{
    boot_app();
    Router::get('/g', fn() => 'get');
    foreach (['post', 'put', 'patch', 'delete'] as $verb) Router::$verb('/s', fn() => strtoupper($verb));
    Router::post('/exempt', fn() => 'exempt');
    Router::exemptCsrf('/exempt');
    Router::post('/nocsrf', fn() => 'nocsrf')->withoutCsrf();
    return Session::csrfToken();
}

test('CSRF: POST, PUT, PATCH and DELETE are rejected (419) without a token', function () {
    csrf_app();
    foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $verb) {
        $e = throws(HttpException::class, fn() => Router::dispatch(req($verb, '/s')), '', $verb);
        eq(419, $e->statusCode(), $verb);
    }
});

test('CSRF: a wrong token is rejected, GET never needs one', function () {
    csrf_app();
    throws(HttpException::class, fn() => Router::dispatch(req('POST', '/s', [], ['HTTP_X_CSRF_TOKEN' => 'wrong'])));
    throws(HttpException::class, fn() => Router::dispatch(req('POST', '/s', ['_token' => ''])));
    eq('get', Router::dispatch(req('GET', '/g'))->body());
});

test('CSRF: the token is accepted from the body, X-CSRF-TOKEN and X-XSRF-TOKEN on every verb', function () {
    $token = csrf_app();
    foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $verb) {
        eq($verb, Router::dispatch(req($verb, '/s', ['_token' => $token]))->body(), "$verb body");
        eq($verb, Router::dispatch(req($verb, '/s', [], ['HTTP_X_CSRF_TOKEN' => $token]))->body(), "$verb header");
        eq($verb, Router::dispatch(req($verb, '/s', [], ['HTTP_X_XSRF_TOKEN' => $token]))->body(), "$verb xsrf header");
    }
    $form = new Request('POST', '/s', [], 'x=1&_token=' . $token, ['CONTENT_TYPE' => 'application/x-www-form-urlencoded']);
    eq('POST', Router::dispatch($form)->body(), 'form-encoded body');
});

test('CSRF: exempt paths and ->withoutCsrf() routes skip the check', function () {
    csrf_app();
    eq('exempt', Router::dispatch(req('POST', '/exempt'))->body());
    eq('nocsrf', Router::dispatch(req('POST', '/nocsrf'))->body());
});

test('CSRF: the middleware class verifies and valid() reports without throwing', function () {
    $token = csrf_app();
    $good = req('POST', '/s', ['_token' => $token]);
    $bad = req('POST', '/s', ['_token' => 'x']);
    ok(Csrf::valid($good) && !Csrf::valid($bad));
    eq(null, (new Csrf())->handle($good));
    throws(HttpException::class, fn() => (new Csrf())->handle($bad));
});

test('CSRF: the token rotates when the session is regenerated (login/logout)', function () {
    $before = csrf_app();
    eq($before, Session::csrfToken(), 'stable within a session');
    Session::regenerate();
    $after = Session::csrfToken();
    ok($after !== $before && strlen($after) === 64, 'new 64-char token');
    throws(HttpException::class, fn() => Router::dispatch(req('POST', '/s', ['_token' => $before])), '', 'old token dead');
});

test('CSRF helpers: __csrf() renders the field, __verifyCsrf() checks a given token', function () {
    $token = csrf_app();
    has("name='_token' value='$token'", __csrf());
    ok(__verifyCsrf($token));
    ok(__verifyCsrf(['_token' => $token]));
    throws(HttpException::class, fn() => __verifyCsrf('nope'), 'CSRF');
    Request::setCurrent(req('POST', '/s', [], ['HTTP_X_CSRF_TOKEN' => $token]));
    ok(__verifyCsrf(), 'falls back to the current request');
});

test('Session: get/set/has/clear/pull', function () {
    Session::init();
    Session::set('a', 1);
    ok(Session::has('a'));
    eq(1, Session::get('a'));
    eq('d', Session::get('nope', 'd'));
    eq(1, Session::pull('a'));
    ok(!Session::has('a'));
    Session::set('b', 2);
    Session::clear('b');
    eq(null, Session::get('b'));
});

test('Session: flash data survives exactly the next request, and getFlash() consumes it', function () {
    Session::init();
    Session::flash('msg', 'hello');
    ok(Session::hasFlash('msg'));
    eq('hello', Session::peekFlash('msg'), 'peek does not consume');
    Session::init(); // next request
    eq('hello', Session::getFlash('msg'), 'readable on the next request');
    eq(null, Session::getFlash('msg'), 'consumed');

    Session::flash('unread', 'x');
    Session::init(); // request after the flash: still there
    ok(Session::hasFlash('unread'));
    Session::init(); // one more: dropped
    ok(!Session::hasFlash('unread'), 'unread flash expires');
});

test('Session::destroy() clears everything', function () {
    Session::init();
    Session::set('x', 1);
    Session::destroy();
    eq([], $_SESSION);
});

test('Alerts: sendAlert() JSON shape, flashed alert read once, busy loader flag', function () {
    Session::init();
    $json = sendAlert('error', 'Bad thing', true, ['go' => 'x']);
    eq(['type' => 'error', 'msg' => 'Bad thing', 'callback' => ['go' => 'x']], json_decode($json, true));
    Session::init();
    has("<div class='alerts d-none'>", __getAlerts());
    eq('', __getAlerts(), 'only once');

    __busyLoader();
    Session::init();
    eq(true, __busyLoader(false));
    eq(false, __busyLoader(false));
});

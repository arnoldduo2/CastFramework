<?php

declare(strict_types=1);

use Cast\App\Application;
use Cast\Core\Session;
use Cast\Http\Request;

/** Boot a copy of the starter app against an in-memory SQLite database. */
function starter(bool $lazy = false): Application
{
    $source = dirname(__DIR__, 2) . '/starter';
    $dir = app_dir();
    exec(sprintf('cd %s && tar --exclude=./vendor --exclude=./storage --exclude=./.env -cf - . | tar -xf - -C %s', escapeshellarg($source), escapeshellarg($dir)));
    file_put_contents("$dir/.env", "APP_NAME=\"Cast Starter\"\nAPP_ENV=development\nAPP_DEBUG=true\nDB_CONN=sqlite\nDB_NAME=:memory:\n");
    mkdir("$dir/storage", 0777, true);

    static $registered = false;
    if (!$registered) {
        spl_autoload_register(function (string $class) use ($dir) {
            if (str_starts_with($class, 'App\\')) {
                $file = $GLOBALS['starter_dir'] . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
                if (is_file($file)) require $file;
            }
        });
        $registered = true;
    }
    $GLOBALS['starter_dir'] = $dir;

    $app = require "$dir/bootstrap/app.php";
    $app->boot();
    $app->make('migrator')->migrate();       // the tables come from database/migrations
    \Cast\Database\SeederRunner::run('UserSeeder');
    if (!$lazy) \Cast\Core\Config::set('spa.initial', 'inline');   // most tests look at the page content itself
    return $app;
}

/** A form-style (browser) request. */
function form(string $method, string $uri, array $fields = []): Request
{
    $fields += ['_token' => Session::csrfToken()];
    return new Request($method, $uri, [], http_build_query($fields), ['CONTENT_TYPE' => 'application/x-www-form-urlencoded']);
}

function api(string $method, string $uri, array $body = []): Request
{
    return req($method, $uri, $body, ['HTTP_X_CSRF_TOKEN' => Session::csrfToken(), 'HTTP_ACCEPT' => 'application/json']);
}

function login_admin(): void
{
    $r = handle(form('POST', '/login', ['email' => 'admin@example.com', 'password' => 'password']));
    eq(302, $r->statusCode());
}

test('Starter: the home page renders with the layout, components, and auto-loaded CSS', function () {
    starter();
    $r = handle(new Request('GET', '/'));
    eq(200, $r->statusCode());
    $html = $r->body();
    has('<title>Cast Starter | Home</title>', $html);
    has('<section class="card">', $html, 'Card component');
    has('<h2>Welcome</h2>', $html);
    has('class="btn btn-primary" href="/items"', $html, 'component props from {expressions}');
    has("href='/css/app.css", $html, 'app.css is found by __modules()');
    lacks('items.css', $html);
    has('name="csrf-token"', $html);
    has('Log in', $html);
});

test('Starter: guests are sent to /login; the login form carries the CSRF field', function () {
    starter();
    $r = handle(new Request('GET', '/items'));
    eq(302, $r->statusCode());
    eq('/login', $r->getHeader('Location'));
    $login = handle(new Request('GET', '/login'));
    eq(200, $login->statusCode());
    has("name='_token' value='" . Session::csrfToken() . "'", $login->body());
});

test('Starter: logging in needs a valid token, valid credentials, and rotates the CSRF token', function () {
    starter();
    $old = Session::csrfToken();
    eq(419, handle(new Request('POST', '/login', [], 'email=admin@example.com&password=password', ['CONTENT_TYPE' => 'application/x-www-form-urlencoded']))->statusCode());

    $bad = handle(form('POST', '/login', ['email' => 'admin@example.com', 'password' => 'wrong']));
    eq(302, $bad->statusCode());
    eq('/login', $bad->getHeader('Location'));
    eq(['email' => 'Those details do not match our records.'], Session::peekFlash('input_errors'));

    $invalid = handle(form('POST', '/login', ['email' => 'not-an-email', 'password' => '']));
    eq(302, $invalid->statusCode());
    ok(isset(Session::peekFlash('input_errors')['email']) && isset(Session::peekFlash('input_errors')['password']));

    $ok = handle(form('POST', '/login', ['email' => 'admin@example.com', 'password' => 'password']));
    eq(302, $ok->statusCode());
    eq('/items', $ok->getHeader('Location'));
    ok(Session::csrfToken() !== $old, 'token rotated on login');
    eq('admin@example.com', $_SESSION['login']['email']);
    ok(!isset($_SESSION['login']['password']), 'no hash in the session');
    eq(['manage-items'], $_SESSION['login']['permissions']);
    eq('/items', handle(new Request('GET', '/login'))->getHeader('Location'), 'signed-in users skip the login page');
});

test('Starter: the items page lists items (data in attributes via jsonQuotes) and loads page CSS/JS modules', function () {
    starter();
    login_admin();
    handle(api('POST', '/items', ['name' => "O'Brien Bolt", 'qty' => 5, 'price' => 1.5]));
    $r = handle(new Request('GET', '/items'));
    eq(200, $r->statusCode());
    $html = $r->body();
    has('<title>Cast Starter | Items</title>', $html);
    has('O&#039;Brien Bolt', $html);
    has('$ 1.50', $html, 'price() from the app-owned helper');
    has('data-item="{&#39;id&#39;:1', $html);
    has("href='/css/items/items.css", $html);
    has("src='/js/items/items.module.js", $html);
    has("src='/js/app/app.module.js", $html);
    has('log out', $html);
});

test('Starter: items API over POST, PUT and DELETE (JSON), with validation and permissions', function () {
    starter();
    login_admin();

    $r = handle(api('POST', '/items', ['name' => 'Bolt', 'qty' => 5, 'price' => 1.5]));
    eq(201, $r->statusCode());
    eq(['status' => 'success', 'msg' => 'Item added.', 'data' => ['id' => 1]], $r->data());

    $r = handle(api('POST', '/items', ['name' => 'B', 'qty' => -1, 'price' => 'x']));
    eq(422, $r->statusCode());
    eq(['name', 'qty', 'price'], array_keys($r->data()['data']['errors']));

    $r = handle(api('PUT', '/items/1', ['name' => 'Bolt XL', 'qty' => 9, 'price' => 2]));
    eq(200, $r->statusCode());
    eq(['id' => 1, 'name' => 'Bolt XL', 'qty' => 9, 'price' => 2], $r->data()['data']);
    eq('Bolt XL', \App\Models\Items::getOne(1)['name']);
    eq(404, handle(api('PUT', '/items/99', ['name' => 'Ghost', 'qty' => 1, 'price' => 1]))->statusCode());

    eq(200, handle(api('DELETE', '/items/1'))->statusCode());
    eq(404, handle(api('DELETE', '/items/1'))->statusCode());
});

test('Starter: browser form submit adds an item and redirects; wrong verb on /items/{id} is 405', function () {
    starter();
    login_admin();
    $r = handle(form('POST', '/items', ['name' => 'Washer', 'qty' => '3', 'price' => '0.5']));
    eq(302, $r->statusCode());
    eq('/items', $r->getHeader('Location'));
    eq(1, count(\App\Models\Items::getAll()));

    $bad = handle(new Request('GET', '/items/1'));
    eq(405, $bad->statusCode());
    eq('PUT, DELETE', $bad->getHeader('Allow'));
});

test('Starter: deleting needs the manage-items permission (403 without it) and a CSRF token (419)', function () {
    starter();
    login_admin();
    handle(api('POST', '/items', ['name' => 'Bolt', 'qty' => 1, 'price' => 1]));

    eq(419, handle(req('DELETE', '/items/1', [], ['HTTP_ACCEPT' => 'application/json']))->statusCode());
    $_SESSION['login']['permissions'] = [];
    $r = handle(api('DELETE', '/items/1'));
    eq(403, $r->statusCode());
    has('manage-items', $r->data()['msg']);
    eq(1, count(\App\Models\Items::getAll()), 'nothing was deleted');
});

test('Starter: logout clears the session; the error pages work', function () {
    starter();
    login_admin();
    $r = handle(form('POST', '/logout'));
    eq(302, $r->statusCode());
    ok(!isset($_SESSION['login']));
    eq('/login', handle(new Request('GET', '/items'))->getHeader('Location'));

    eq(404, handle(new Request('GET', '/nope'))->statusCode());
    eq(405, handle(new Request('POST', '/'))->statusCode());
    $boom = handle(new Request('GET', '/_boom'));
    eq(500, $boom->statusCode());
    has('This is what a server error looks like.', $boom->body());
});

test('Starter: maintenance mode takes the whole app down and back up', function () {
    $app = starter();
    app('maintenance')->down('Updating the items database', 'letmein');
    $r = handle(new Request('GET', '/'));
    eq(503, $r->statusCode());
    has('Updating the items database', $r->body());
    eq(200, handle(new Request('GET', '/', [], '', ['HTTP_X_MAINTENANCE_SECRET' => 'letmein']))->statusCode());
    app('maintenance')->up();
    eq(200, handle(new Request('GET', '/'))->statusCode());
});

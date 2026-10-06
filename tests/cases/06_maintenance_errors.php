<?php

declare(strict_types=1);

use Cast\App\Application;
use Cast\Core\Maintenance\FileMaintenanceStore;
use Cast\Core\Maintenance\MaintenanceManager;
use Cast\Core\Router;
use Cast\Core\Updates\CallbackUpdater;
use Cast\Http\HttpException;
use Cast\Http\Request;

function maint(): MaintenanceManager
{
    return app('maintenance');
}

test('Maintenance: down() and up() through the file store; the page is a 503 with Retry-After', function () {
    boot_app();
    Router::get('/', fn() => 'home');
    eq(200, handle(req('GET', '/'))->statusCode());

    maint()->down('Back at noon.', null, 120);
    $r = handle(req('GET', '/'));
    eq(503, $r->statusCode());
    has('Under maintenance', $r->body());
    has('Back at noon.', $r->body());
    eq('120', $r->getHeader('Retry-After'));
    ok(is_file(app()->storagePath('framework/maintenance.json')), 'state is a JSON file');

    maint()->up();
    eq(200, handle(req('GET', '/'))->statusCode());
    ok(!is_file(app()->storagePath('framework/maintenance.json')));
});

test('Maintenance: JSON requests get the R11 error shape', function () {
    boot_app();
    Router::get('/api', fn() => 'x');
    maint()->down('Down for upgrades');
    $r = handle(req('GET', '/api', [], ['HTTP_ACCEPT' => 'application/json']));
    eq(503, $r->statusCode());
    eq(['status' => 'error', 'msg' => 'Down for upgrades'], $r->data());
});

test('Maintenance: the secret lets a request through (header, query string or cookie), wrong ones do not', function () {
    boot_app();
    Router::get('/', fn() => 'home');
    maint()->down('Down', 'open-sesame');

    eq(503, handle(req('GET', '/'))->statusCode());
    eq(200, handle(req('GET', '/', [], ['HTTP_X_MAINTENANCE_SECRET' => 'open-sesame']))->statusCode(), 'header');
    eq(200, handle(req('GET', '/', [], [], ['maintenance_secret' => 'open-sesame']))->statusCode(), 'query');
    eq(503, handle(req('GET', '/', [], ['HTTP_X_MAINTENANCE_SECRET' => 'nope']))->statusCode(), 'wrong secret');

    $cookie = hash('sha256', 'open-sesame');
    eq(200, handle(new Request('GET', '/', [], '', [], [], ['cast_maintenance' => $cookie]))->statusCode(), 'cookie');
    $raw = json_decode((string) file_get_contents(app()->storagePath('framework/maintenance.json')), true);
    ok(!str_contains(json_encode($raw), 'open-sesame'), 'the secret is stored hashed');
});

test('Maintenance: a bypass callback in config lets some requests in (e.g. admins)', function () {
    boot_app(['config/maintenance.php' => "<?php return ['bypass' => fn(\$request) => \$request->header('X-Admin') === '1'];"]);
    Router::get('/', fn() => 'home');
    maint()->down('Down');
    eq(503, handle(req('GET', '/'))->statusCode());
    eq(200, handle(req('GET', '/', [], ['HTTP_X_ADMIN' => '1']))->statusCode());
});

test('Maintenance: a scheduled countdown reports the time left, then switches to active', function () {
    boot_app();
    Router::get('/', fn() => 'home');
    maint()->schedule(60, 'Starting soon');
    $status = maint()->status();
    ok($status['scheduled'] && !$status['active'] && $status['seconds_remaining'] > 55, 'counting down');
    eq(200, handle(req('GET', '/'))->statusCode(), 'still up during the countdown');

    $file = app()->storagePath('framework/maintenance.json');
    $data = json_decode((string) file_get_contents($file), true);
    $data['target_timestamp'] = time() - 1;
    file_put_contents($file, json_encode($data));
    ok(maint()->status()['active'], 'countdown finished => active');
    eq(503, handle(req('GET', '/'))->statusCode());
    ok(!maint()->status()['scheduled']);
});

test('Maintenance: schedule() waits at least 5 seconds', function () {
    boot_app();
    maint()->schedule(1);
    ok(maint()->status()['seconds_remaining'] >= 4);
});

test('FileMaintenanceStore: round trip, clear, and unreadable files', function () {
    $dir = app_dir();
    $store = new FileMaintenanceStore("$dir/deep/state.json");
    eq([], $store->read());
    $store->write(['a' => 1]);
    eq(['a' => 1], $store->read());
    file_put_contents("$dir/deep/state.json", 'not json');
    eq([], $store->read());
    $store->clear();
    ok(!is_file("$dir/deep/state.json"));
});

test('Updater: a pending update shows the updating page (503) with the versions', function () {
    $app = boot_app();
    Router::get('/', fn() => 'home');
    $app->set('updater', new CallbackUpdater(fn() => '1.0.0', fn() => '1.2.0', fn() => true));
    $r = handle(req('GET', '/'));
    eq(503, $r->statusCode());
    has('Updating the system', $r->body());
    has('1.0.0', $r->body());
    has('1.2.0', $r->body());
    eq('30', $r->getHeader('Retry-After'));
});

test('Updater: with app.auto_update the update runs and the request goes through', function () {
    $app = boot_app(['config/app.php' => "<?php return ['auto_update' => true];"]);
    Router::get('/', fn() => 'home');
    $version = '1.0.0';
    $app->set('updater', new CallbackUpdater(function () use (&$version) { return $version; }, fn() => '2.0.0', function () use (&$version) { $version = '2.0.0'; return true; }));
    eq(200, handle(req('GET', '/'))->statusCode());
    eq('2.0.0', $version);
});

test('Updater: a failing auto-update keeps showing the updating page; up-to-date apps are unaffected', function () {
    $app = boot_app(['config/app.php' => "<?php return ['auto_update' => true];"]);
    Router::get('/', fn() => 'home');
    $app->set('updater', new CallbackUpdater(fn() => '1.0.0', fn() => '2.0.0', fn() => false));
    eq(503, handle(req('GET', '/'))->statusCode());
    $app->set('updater', new CallbackUpdater(fn() => '2.0.0', fn() => '2.0.0', fn() => true));
    eq(200, handle(req('GET', '/'))->statusCode());
});

test('Error pages: 404, 403, 405, 419 render a self-contained HTML page with the right status', function () {
    boot_app();
    Router::get('/ok', fn() => 'ok');
    Router::get('/forbidden', fn() => throw new HttpException(403, 'No entry'));
    Router::get('/boom', fn() => abort(500, 'Kaput'));

    $r = handle(req('GET', '/missing'));
    eq(404, $r->statusCode());
    has('<title>Test | 404 Page Not Found</title>', $r->body());
    has('Back to Test', $r->body());
    lacks('http://', $r->body(), 'no external assets');
    lacks('https://', $r->body(), 'no external assets');
    has('text/html', (string) $r->getHeader('Content-Type'));

    $r = handle(req('GET', '/forbidden'));
    eq(403, $r->statusCode());
    has('No entry', $r->body());
    has('permission', $r->body());

    $r = handle(req('POST', '/ok'));
    eq(405, $r->statusCode());
    eq('GET', $r->getHeader('Allow'));

    $r = handle(req('GET', '/boom'));
    eq(500, $r->statusCode());
    has('Kaput', $r->body());
});

test('Error pages: 419 for a missing CSRF token; JSON clients get the R11 shape instead of HTML', function () {
    boot_app();
    Router::post('/save', fn() => 'x');
    $r = handle(req('POST', '/save'));
    eq(419, $r->statusCode());
    has('expired', strtolower($r->body()));

    $r = handle(req('POST', '/save', [], ['HTTP_ACCEPT' => 'application/json']));
    eq(419, $r->statusCode());
    eq('error', $r->data()['status']);
    $r = handle(req('GET', '/nope', [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']));
    eq(404, $r->statusCode());
    ok($r->isJson());
});

test('Error pages: error messages are HTML-escaped', function () {
    boot_app();
    Router::get('/x', fn() => abort(400, '<script>alert(1)</script>'));
    $r = handle(req('GET', '/x'));
    lacks('<script>alert(1)</script>', $r->body());
    has('&lt;script&gt;', $r->body());
});

test('Error pages: the app can override errors/error, errors/{code} and maintenance', function () {
    boot_app([
        'resources/views/errors/404.cast.php' => '<h1>Custom 404 for <?= htchars($appName) ?></h1>',
        'resources/views/maintenance.cast.php' => '<h1>Custom maintenance: <?= htchars($message) ?></h1>',
    ]);
    Router::get('/ok', fn() => 'ok');
    Router::post('/save', fn() => 'x');
    eq('<h1>Custom 404 for Test</h1>', handle(req('GET', '/nope'))->body());
    has('Page Expired', handle(req('POST', '/save'))->body(), 'other codes use the framework page');
    maint()->down('Back later');
    eq('<h1>Custom maintenance: Back later</h1>', handle(req('GET', '/ok'))->body());
});

test('Error pages: render404() helper echoes the page and sets the status', function () {
    boot_app();
    ob_start();
    render404('Gone', 'Nothing here');
    $html = ob_get_clean();
    has('Nothing here', $html);
});

test('ErrorProvider: passes the option names the error-handler package actually reads', function () {
    $app = boot_app(['config/app.php' => "<?php return ['name' => 'Acme', 'base_path' => '/acme', 'env' => 'production', 'debug' => false];"], boot: false);
    $options = \Cast\Services\ErrorProvider::options($app);
    eq('Acme', $options['app_name']);
    eq('production', $options['app_enviroment'], 'the package spells it "enviroment"');
    eq(false, $options['app_debug']);
    eq('/acme/', $options['base_url']);
    eq($app->storagePath('logs') . DIRECTORY_SEPARATOR, $options['log_directory']);
    ok(!array_key_exists('logs_directory', $options), 'a misspelled key would be silently ignored');

    $reads = file_get_contents(__DIR__ . '/../../vendor/anode/error-handler/src/ErrorHandler.php');
    foreach (array_keys($options) as $key) has("'$key'", $reads, "the package reads \"$key\"");
});

test('ErrorProvider: does nothing in the CLI, so tests and console commands keep PHP\'s own handlers', function () {
    $app = boot_app();
    ok(!$app->has('error_handler'));
});

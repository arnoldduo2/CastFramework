<?php

declare(strict_types=1);

use Cast\Core\Router;
use Cast\Http\Request;

/** An app with module gating configured: reports (optional), billing (core), printing (needs a class that does not exist), archive (switched off) */
function modules_app(bool $enabled = true, array $extra = []): \Cast\App\Application
{
    $config = var_export(array_replace([
        'enabled' => $enabled,
        'core' => ['billing' => ['title' => 'Billing']],
        'optional' => ['reports', 'printing' => ['title' => 'Receipt printing', 'requires' => ['Nope\\PrinterService']], 'archive' => ['active' => false]],
    ], $extra), true);
    $app = boot_app(['config/modules.php' => "<?php return $config;"]);
    mkdir($app->basePath('storage'), 0777, true);
    foreach (['reports', 'billing', 'printing', 'archive', 'ghost'] as $m) {
        Router::module($m, fn() => Router::get("/$m", fn() => "the $m page"));
    }
    Router::get('/open', fn() => 'always');
    return $app;
}

test('modules: gating is off by default, so every gate lets everything through', function () {
    boot_app([]);
    Router::module('anything', fn() => Router::get('/x', fn() => 'x page'));
    eq(200, handle(new Request('GET', '/x'))->statusCode());
    ok(module_active('anything'), 'module_active() is true with gating off');
    eq(false, app('modules')->enabled());
});

test('modules: an active module runs; inactive, unbuilt and unlisted ones show the fallback page, not a crash', function () {
    modules_app();
    eq('the reports page', handle(new Request('GET', '/reports'))->body());
    eq('the billing page', handle(new Request('GET', '/billing'))->body());
    eq('always', handle(new Request('GET', '/open'))->body());

    foreach (['archive' => 'Module inactive', 'printing' => 'Module unavailable', 'ghost' => 'Module unavailable'] as $name => $headline) {
        $r = handle(new Request('GET', "/$name"));
        eq(503, $r->statusCode(), $name);
        has($headline, $r->body());
        eq('300', $r->getHeader('Retry-After'), 'Retry-After');
        lacks("the $name page", $r->body());
    }
    has('Receipt printing', handle(new Request('GET', '/printing'))->body());
    $debug = handle(new Request('GET', '/ghost'))->body();
    has('not listed in config/modules.php', $debug);
});

test('modules: JSON, Cast and API requests get the error envelope, not HTML', function () {
    modules_app();
    $json = handle(new Request('GET', '/archive', [], '', ['HTTP_ACCEPT' => 'application/json']));
    eq(503, $json->statusCode());
    $body = json_decode($json->body(), true);
    eq('error', $body['status']);
    has('Archive is not available', $body['msg']);
    $cast = handle(new Request('GET', '/archive', [], '', ['HTTP_X_CAST_REQUEST' => '1']));
    eq(503, $cast->statusCode());
    eq('reload', json_decode($cast->body(), true)['data']['type'], 'the SPA client loads the whole fallback page');
});

test('modules: modules:disable / enable switch an optional module; a core module cannot be switched off; the fallback page can be overridden', function () {
    $app = modules_app();
    $class = \Cast\Console\Commands\ModulesCommand::class;
    [$c] = cmd_run_module($class, 'disable', ['reports'], $app);
    eq(0, $c);
    eq(503, handle(new Request('GET', '/reports'))->statusCode());
    ok(!module_active('reports'));
    [$c2] = cmd_run_module($class, 'enable', ['reports'], $app);
    eq(0, $c2);
    eq(200, handle(new Request('GET', '/reports'))->statusCode());
    [$c3, $o3] = cmd_run_module($class, 'disable', ['billing'], $app);
    eq(1, $c3);
    has('core module', $o3);
    [$c4] = cmd_run_module($class, 'disable', ['nope'], $app);
    eq(1, $c4);
    [$c5] = cmd_run_module($class, 'enable', ['archive'], $app);
    eq(200, handle(new Request('GET', '/archive'))->statusCode(), 'a switch beats active => false in the config');
    ok(true);

    mkdir($app->viewsPath('errors'), 0777, true);
    file_put_contents($app->viewsPath('errors/module.cast.php'), '<h1>Our own page for <?= htchars($module["title"]) ?></h1>');
    cmd_run_module($class, 'disable', ['reports'], $app);
    has('Our own page for Reports', handle(new Request('GET', '/reports'))->body());
});

test('modules: modules:list shows core and optional; modules:check fails on a core module that is not built, and deploy:check says so', function () {
    $app = modules_app();
    $class = \Cast\Console\Commands\ModulesCommand::class;
    [$code, $out] = cmd_run_module($class, 'list', [], $app);
    eq(0, $code);
    foreach (['billing', 'core', 'reports', 'optional', 'printing', 'unbuilt', 'archive', 'inactive'] as $word) has($word, $out);
    [, $json] = cmd_run_module($class, 'list', ['--json'], $app);
    $data = json_decode($json, true);
    ok($data['enabled'] && $data['modules'][0]['name'] === 'billing' && $data['modules'][0]['core'], 'core modules come first');
    [$ok] = cmd_run_module($class, 'check', [], $app);
    eq(0, $ok, 'optional modules only warn');

    $broken = modules_app(true, ['core' => ['billing' => ['requires' => ['Missing\\BillingController']]]]);
    [$bad, $o] = cmd_run_module($class, 'check', [], $broken);
    eq(1, $bad);
    has('FAIL  core module billing is unbuilt', $o);
    [, $deploy] = cmd_run(\Cast\Console\Commands\DeployCheckCommand::class, ['--skip-db', '--allow-debug'], null, $broken);
    has('FAIL  core module billing is unbuilt', $deploy);
    has('note  optional module archive is inactive', $deploy);

    $off = modules_app(false);
    eq(0, cmd_run_module($class, 'check', [], $off)[0]);
});

test('modules: a module_store of your own (a database table) decides, and gating can use a function for active', function () {
    $app = modules_app();
    \Cast\Core\Config::set('modules.optional', ['reports' => ['active' => fn() => false]]);
    $app->singleton('modules', fn() => new \Cast\Core\Modules\Modules($app->make('module_store')));
    eq(503, handle(new Request('GET', '/reports'))->statusCode(), 'active as a function');
    $app->singleton('module_store', fn() => new class implements \Cast\Contracts\ModuleStore {
        public function isEnabled(string $module): ?bool { return $module === 'reports' ? true : null; }
        public function set(string $module, bool $enabled): void {}
    });
    $app->singleton('modules', fn() => new \Cast\Core\Modules\Modules($app->make('module_store')));
    eq(200, handle(new Request('GET', '/reports'))->statusCode(), 'the store says on');
});

function cmd_run_module(string $class, string $action, array $args, \Cast\App\Application $app): array
{
    $screen = fopen('php://memory', 'w+');
    $cmd = new $class($app, $action);
    $code = $cmd->handle(new \Cast\Console\Input([$cmd->name(), ...$args]), new \Cast\Console\Output($screen, false));
    rewind($screen);
    return [$code, (string) stream_get_contents($screen)];
}

test('make:module writes the controller, views and a gated routes file, lists the module in config/modules.php, and the result works behind the gate', function () {
    $app = boot_app(['.env' => "APP_NAME=Shop\nCAST_MODULES=true\n"]);
    $class = \Cast\Console\Commands\MakeModuleCommand::class;
    $run = function (array $args) use ($class, $app): array {
        $screen = fopen('php://memory', 'w+');
        $cmd = new $class($app);
        $code = $cmd->handle(new \Cast\Console\Input(['make:module', ...$args, '-n']), new \Cast\Console\Output($screen, false));
        rewind($screen);
        return [$code, (string) stream_get_contents($screen)];
    };
    [$code, $out] = $run(['PurchaseOrders', '--core', '--model']);
    eq(0, $code, $out);
    foreach (['app/Controllers/PurchaseOrdersController.php', 'routes/modules/purchase-orders.php', 'resources/views/purchase-orders/purchase-orders.cast.php', 'resources/views/purchase-orders/partials/purchase-orders.cast.php', 'app/Models/PurchaseOrders.php', 'config/modules.php'] as $f) ok(is_file($app->basePath($f)), "$f written");
    foreach (['app/Controllers/PurchaseOrdersController.php', 'routes/modules/purchase-orders.php', 'app/Models/PurchaseOrders.php'] as $f) {
        exec('php -l ' . escapeshellarg($app->basePath($f)) . ' 2>&1', $o, $lint);
        eq(0, $lint, "$f parses");
    }
    $routes = (string) file_get_contents($app->basePath('routes/modules/purchase-orders.php'));
    has("Router::module('purchase-orders'", $routes);
    has('module inactive or unavailable', $routes, 'the routes file explains the gate');
    $config = (string) file_get_contents($app->basePath('config/modules.php'));
    $cfg = (static fn() => require $app->basePath('config/modules.php'))();
    eq(['purchase-orders'], array_keys($cfg['core']));
    eq('Purchase Orders', $cfg['core']['purchase-orders']['title']);
    has('PurchaseOrdersController::class', $config);

    [$again, $o2] = $run(['PurchaseOrders']);
    eq(1, $again);
    has('exists', $o2);
    [$c3] = $run(['Reports']);
    eq(0, $c3);
    $cfg = (static fn() => eval('?>' . file_get_contents($app->basePath('config/modules.php'))))();
    eq(['reports'], array_keys($cfg['optional']));
    [$c4, $o4] = $run(['Reports']);
    eq(1, $c4, 'its files exist now');
    [$bad] = $run(['bad name!']);
    eq(1, $bad);
});

test('module routes files in routes/modules/ are loaded by the framework and gated', function () {
    $app = boot_app([
        '.env' => "APP_NAME=Shop\nCAST_MODULES=true\n",
        'config/modules.php' => "<?php return ['core' => [], 'optional' => ['notes' => ['active' => false], 'tasks']];",
        'routes/modules/notes.php' => "<?php \\Cast\\Core\\Router::module('notes', fn() => \\Cast\\Core\\Router::get('/notes', fn() => 'notes page'));",
        'routes/modules/tasks.php' => "<?php \\Cast\\Core\\Router::module('tasks', fn() => \\Cast\\Core\\Router::get('/tasks', fn() => 'tasks page'));",
    ]);
    mkdir($app->basePath('storage'), 0777, true);
    eq('tasks page', handle(new Request('GET', '/tasks'))->body());
    eq(503, handle(new Request('GET', '/notes'))->statusCode());
});

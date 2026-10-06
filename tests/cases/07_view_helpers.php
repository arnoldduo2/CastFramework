<?php

declare(strict_types=1);

use Cast\Core\Config;
use Cast\Core\View;
use Cast\Http\Controller;
use Cast\Http\Response;

function view_app(array $files = [], array $config = []): View
{
    $configFile = $config ? ['config/app.php' => '<?php return ' . var_export($config, true) . ';'] : [];
    $app = boot_app($files + $configFile);
    return $app->make('view');
}

test('View: renders a view with its data as variables and as $data', function () {
    $view = view_app(['resources/views/pages/demo.cast.php' => '<p><?= htchars($name) ?>|<?= htchars($data["name"]) ?>|<?= htchars($data["extra"] ?? "none") ?></p>']);
    eq('<p>Ann|Ann|none</p>', $view->render('pages.demo', ['name' => 'Ann']));
    eq('<p>Bo|Bo|none</p>', $view->render('pages/demo', ['name' => 'Bo']), 'slashes work too');
    throws(\CastTemplateEngine\TemplateError::class, fn() => $view->render('missing.view'), 'not found');
});

test('View: share() data reaches every view; explicit data wins', function () {
    $view = view_app(['resources/views/s.cast.php' => '<?= $site ?>/<?= $user ?>']);
    $view->share('site', 'ERP');
    $view->share(['user' => 'shared']);
    eq('ERP/shared', $view->render('s'));
    eq('ERP/own', $view->render('s', ['user' => 'own']));
});

test('View: .cast.php views can use component tags, children and slots', function () {
    $view = view_app([
        'resources/views/components/card.cast.php' => '<div class="card"><h2><?= htchars($title) ?></h2><?= $children ?></div>',
        'resources/views/components/forms/text-input.cast.php' => '<input name="<?= htchars($name) ?>" value="<?= htchars($value ?? "") ?>">',
        'resources/views/page.cast.php' => '<Card title="People"><?php foreach ($people as $p): ?><Forms.TextInput name="p" value={$p} /><?php endforeach ?></Card>',
    ]);
    eq(
        '<div class="card"><h2>People</h2><input name="p" value="a"><input name="p" value="b"></div>',
        preg_replace('/\s+(?=<)|(?<=>)\s+/', '', $view->render('page', ['people' => ['a', 'b']]))
    );
});

test('View: component() renders .cast.php components (camelCased props) and legacy .php components', function () {
    $view = view_app([
        'resources/views/components/badge.cast.php' => '<b><?= htchars($hasLabel ? "L" : "n") ?></b>',
        'resources/views/components/btns/add-new.php' => '<a href="<?= htchars($data["link"] ?? "#") ?>">add</a>',
        'resources/views/components/attrs/item.php' => '<?php return "<i>" . ($data["name"] ?? "?") . "</i>";',
    ]);
    eq('<b>L</b>', $view->component('badge', ['has_label' => true]));
    eq('<a href="fleet">add</a>', $view->component('btns.add-new', ['link' => 'fleet']));
    eq('<i>x</i>', $view->component('attrs.item', ['name' => 'x'], true), 'asVar returns what the file returns');
    eq('', $view->component('does.not.exist'));
    eq('<a href="#">add</a>', $view->component('btns.add-new', 'ignored string data'));
});

test('View: a legacy component that throws does not leave output buffers open', function () {
    $view = view_app(['resources/views/components/bad.php' => '<?php echo "partial"; throw new LogicException("x");']);
    $level = ob_get_level();
    throws(LogicException::class, fn() => $view->component('bad'));
    eq($level, ob_get_level());
});

test('View: partial() echoes and returns the output; layouts compose pages', function () {
    $view = view_app([
        'resources/views/layouts/header.cast.php' => '<head><?= htchars($data["pageName"]) ?></head>',
        'resources/views/mod/mod.cast.php' => '<?php __includes("layouts.header", $data); ?><main><?php __includes("mod.partials." . $data["pageName"], $data); ?></main>',
        'resources/views/mod/partials/list.cast.php' => '<ul>list</ul>',
    ]);
    eq('<head>list</head><main><ul>list</ul></main>', $view->render('mod.mod', ['pageName' => 'list']));
    ob_start();
    $returned = $view->partial('mod.partials.list');
    eq('<ul>list</ul>', ob_get_clean());
    eq('<ul>list</ul>', $returned);
});

test('View: modules() builds <link>/<script> only when the file exists, with the base path and version', function () {
    $view = view_app(['resources/css/accounting/assets.css' => 'a{}', 'resources/js/accounting/assets.module.js' => '1', 'resources/css/accounting/accounting.css' => 'a{}'], ['base_path' => '/erp-app', 'version' => '3.1', 'debug' => false]);
    eq("<link rel='stylesheet' class='resources' href='/erp-app/css/accounting/assets.css?v=3.1'/>", $view->modules('accounting.assets', 'css'));
    eq("<script type='text/javascript' src='/erp-app/js/accounting/assets.module.js?v=3.1'></script>", $view->modules('accounting.assets', 'js'));
    eq('', $view->modules('accounting.missing', 'css'));
    eq('', $view->modules('', 'js'));
    eq('', $view->modules('accounting.accounting', 'js'), 'css exists but js does not');
    eq($view->modules('accounting.assets', 'css'), __modules('accounting.assets', 'css'), 'helper');
});

test('View: views() echoes (legacy behaviour), respond() and Controller::view() give a Response', function () {
    $view = view_app(['resources/views/hi.cast.php' => 'hi <?= $n ?>']);
    ob_start();
    eq(true, views('hi', ['n' => 1]));
    eq('hi 1', ob_get_clean());

    $r = $view->respond('hi', ['n' => 2], 201);
    ok($r instanceof Response);
    eq(201, $r->statusCode());
    eq('hi 2', $r->body());

    $controller = new class extends Controller {
        public function page(): Response { return $this->view('hi', ['n' => 3]); }
    };
    eq('hi 3', $controller->page()->body());
});

test('View: the compiled cache lives under storage/framework/views and recompiles in debug when the file changes', function () {
    $app = boot_app(['resources/views/a.cast.php' => 'one']);
    $view = $app->make('view');
    eq('one', $view->render('a'));
    ok(count(glob($app->storagePath('framework/views/*.php'))) >= 1);
    sleep(1);
    file_put_contents($app->viewsPath('a.cast.php'), 'two');
    eq('one', $view->render('a'), 'within one request the compiled file is reused');
    eq('two', (new View($app))->render('a'), 'the next request sees the change (debug mode)');
});

test('Helpers: the framework registers helper files by category and every function is guarded', function () {
    foreach (['app', 'env', 'config', 'views', 'Component', '__includes', '__modules', 'route', 'route_to', 'getPost', '__csrf', '__verifyCsrf',
        'jsonQuotes', 'jsonValidate', 'snakeCase', 'arrayReducer', 'getDateTime', '__round', '__money', 'sendAlert', 'dd', 'validateParam', 'formValidation', 'useConfig', '__getConfig'] as $fn) {
        ok(function_exists($fn), "$fn() is defined");
    }
    foreach (['core', 'request', 'security', 'strings', 'arrays', 'dates', 'math', 'html', 'validation'] as $category) {
        ok(is_file(__DIR__ . "/../../src/Helpers/$category.php"), "$category.php");
    }
    $source = file_get_contents(__DIR__ . '/../../src/Helpers/core.php');
    eq(substr_count($source, 'if (!function_exists('), preg_match_all('/^\s*function\s+\w+/m', $source), 'one guard per function in core.php');
});

test('Helpers: route(), config(), env(), app(), paths', function () {
    $app = boot_app(['config/app.php' => "<?php return ['base_path' => '/erp-app'];", '.env' => "APP_NAME=Z\nX_KEY=42\n"]);
    eq('/erp-app/dashboard', route('/dashboard'));
    eq('/erp-app/dashboard?tab=a&x=1', route('/dashboard', ['tab' => 'a', 'x' => 1]));
    eq('/erp-app/', route());
    eq('https://other.test/x?y=1', route('https://other.test/x', ['y' => 1]), 'absolute URLs untouched');
    eq('42', env('x_key'));
    eq('Z', config('app.name'));
    eq('d', config('nope', 'd'));
    config(['custom.key' => 'v']);
    eq('v', config('custom.key'));
    ok(app() === $app);
    ok(app('view') instanceof View);
    ok(str_ends_with(storage_path('x'), 'storage' . DIRECTORY_SEPARATOR . 'x') && str_ends_with(base_path(), basename($app->basePath())));
    ok(str_ends_with(resource_path(), 'resources') && str_ends_with(public_path(), 'public'));
});

test('Helpers: route_to() returns the redirect Response in the CLI; abort() throws', function () {
    boot_app(['config/app.php' => "<?php return ['base_path' => '/erp-app'];"]);
    $r = route_to('/login', ['next' => '/x']);
    eq(302, $r->statusCode());
    eq('/erp-app/login?next=%2Fx', $r->getHeader('Location'));
    eq(404, throws(\Cast\Http\HttpException::class, fn() => abort(404))->statusCode());
});

test('Helpers: config helpers read config files and config.json', function () {
    boot_app(['config/colors.php' => "<?php return ['primary' => '#0a0'];", 'config/config.json' => '{"quickLinks":{"a":[1]},"other":"not an array"}']);
    eq(['primary' => '#0a0'], useConfig('colors'));
    eq([], useConfig('missing'));
    eq(['a' => [1]], __getConfig('quickLinks'));
    eq([], __getConfig('missing'), 'a missing key is [] (not the whole file)');
    eq([], __getConfig('other'));
    eq([], __getConfig('x', 'nofile.json'));
});

test('Helpers: version suffix for assets', function () {
    boot_app(['config/app.php' => "<?php return ['debug' => false, 'version' => '2.5'];"]);
    eq('?v=2.5', app_version());
    eq('2.5', app_version(false));
    config(['app.version' => null]);
    eq('?v=1', app_version(), 'no version, not debug');
    config(['app.debug' => true]);
    ok((bool) preg_match('/^\?v=\d+$/', app_version()), 'debug: fresh each time');
});

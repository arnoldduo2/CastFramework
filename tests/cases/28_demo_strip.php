<?php

declare(strict_types=1);

use Cast\Http\Request;

/** demo:strip: each pack leaves an app that boots, with only its own pages */

function stripped_has(string $path): bool
{
    return file_exists($GLOBALS['starter_dir'] . '/' . $path);
}

test('demo:strip --pack=shell leaves the welcome page and nothing else', function () {
    starter(false, 'shell');
    foreach (['app/Controllers/AuthController.php', 'app/Controllers/ItemsController.php', 'app/Controllers/StatsController.php', 'app/Controllers/Api', 'routes/api.php', 'resources/views/items', 'resources/views/auth', 'resources/views/demo', 'resources/css/items', 'database/seeders/UserSeeder.php', 'config/auth.php'] as $gone) {
        ok(!stripped_has($gone), "$gone is gone");
    }
    ok(count(glob($GLOBALS['starter_dir'] . '/database/migrations/*.php')) === 0, 'no migrations left');
    ok(stripped_has('app/Controllers/HomeController.php') && stripped_has('resources/views/layouts/header.cast.php'), 'the shell stays');
    $home = handle(new Request('GET', '/'));
    eq(200, $home->statusCode());
    lacks('Demo the Cast Framework', $home->body());
    lacks('/register', $home->body());
    eq(404, handle(new Request('GET', '/items'))->statusCode());
    eq(404, handle(new Request('GET', '/login'))->statusCode());
    lacks('demo()', (string) file_get_contents($GLOBALS['starter_dir'] . '/app/Controllers/HomeController.php'));
    foreach (['routes/web.php', 'app/Providers/AppServiceProvider.php', 'database/seeders/DatabaseSeeder.php', 'config/app.php'] as $f) {
        exec('php -l ' . escapeshellarg($GLOBALS['starter_dir'] . '/' . $f) . ' 2>&1', $o, $code);
        eq(0, $code, "$f parses");
    }
});

test('demo:strip --pack=crud: Items for everyone, no login', function () {
    starter(false, 'crud');
    ok(!stripped_has('app/Controllers/AuthController.php') && stripped_has('app/Controllers/ItemsController.php'), 'items stay, auth goes');
    ok(!stripped_has('app/Controllers/StatsController.php'), 'stats go');
    $page = handle(new Request('GET', '/items'));
    eq(200, $page->statusCode(), 'items need no login');
    has('href="/items"', handle(new Request('GET', '/'))->body(), 'the menu links to Items');
    lacks('Log in', handle(new Request('GET', '/'))->body());
    $r = handle(form('POST', '/items', ['name' => 'Bolt', 'qty' => '5', 'price' => '1.5']));
    ok($r->statusCode() < 400, 'creating an item works without a login');
});

test('demo:strip --pack=auth: login, register and a private Account page', function () {
    starter(false, 'auth');
    ok(stripped_has('app/Controllers/AccountController.php') && stripped_has('resources/views/account/partials/account.cast.php'), 'the account page is written');
    ok(!stripped_has('app/Controllers/ItemsController.php'), 'items are gone');
    $guest = handle(new Request('GET', '/account'));
    eq(302, $guest->statusCode(), 'guests are sent to the login');
    eq(200, handle(new Request('GET', '/login'))->statusCode());
    login_admin();
    $account = handle(new Request('GET', '/account'));
    eq(200, $account->statusCode());
    has('admin@example.com', $account->body());
    has('title="admin@example.com"', $account->body(), 'Logout button with the email as tooltip');
});

test('demo:strip --pack=auth-crud: Items behind the login', function () {
    starter(false, 'auth-crud');
    ok(stripped_has('app/Controllers/ItemsController.php') && stripped_has('app/Controllers/AuthController.php'), 'both stay');
    ok(!stripped_has('app/Controllers/StatsController.php') && !stripped_has('app/Controllers/AccountController.php'), 'stats and account are not there');
    eq(302, handle(new Request('GET', '/items'))->statusCode(), 'items need a login');
    login_admin();
    eq(200, handle(new Request('GET', '/items'))->statusCode());
});

test('demo:strip: --dry-run changes nothing, an unknown pack is refused, a non-demo app has nothing to strip', function () {
    $app = starter();
    $screen = fopen('php://memory', 'w+');
    $cmd = new \Cast\Console\Commands\DemoStripCommand($app);
    eq(0, $cmd->handle(new \Cast\Console\Input(['demo:strip', '--pack=shell', '--dry-run']), new \Cast\Console\Output($screen)));
    rewind($screen);
    has('app/Controllers/ItemsController.php', (string) stream_get_contents($screen));
    ok(stripped_has('app/Controllers/ItemsController.php'), 'dry run deletes nothing');
    eq(1, $cmd->handle(new \Cast\Console\Input(['demo:strip', '--pack=nope']), new \Cast\Console\Output(fopen('php://memory', 'w+'))));
    \Cast\Core\Config::set('app.demo', false);
    eq(1, $cmd->handle(new \Cast\Console\Input(['demo:strip', '--pack=shell', '--yes']), new \Cast\Console\Output(fopen('php://memory', 'w+'))));
});

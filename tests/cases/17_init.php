<?php

declare(strict_types=1);

/**
 * `cast init` is tested in separate PHP processes, because the generated app has its own `App\` classes
 * that would clash with the starter app's classes loaded by other test files.
 */

function init_dir(bool $composerJson = true): string
{
    $dir = app_dir($composerJson ? ['composer.json' => json_encode(['name' => 'me/my-shop', 'require' => new stdClass()])] : []);
    $framework = dirname(__DIR__, 2);
    // the app's autoloader is Composer's with NO `App\` entry (a fresh app before `composer dump-autoload`): the framework maps it itself
    mkdir("$dir/vendor", 0777, true);
    file_put_contents("$dir/vendor/autoload.php", "<?php\nrequire " . var_export($framework . '/vendor/autoload.php', true) . ";\n");
    return $dir;
}

/** @return array{int, string} */
function cast_in(string $dir, string $args): array
{
    $cmd = sprintf('cd %s && php %s %s 2>&1', escapeshellarg($dir), escapeshellarg(dirname(__DIR__, 2) . '/bin/cast'), $args);
    exec($cmd, $lines, $code);
    return [$code, implode("\n", array_filter($lines, fn($l) => !str_starts_with($l, 'fatal:')))];
}

/** Run a request against the app in $dir; returns [status, body]. */
function app_get(string $dir, string $uri, array $headers = []): array
{
    $script = <<<'PHP'
require $argv[1] . '/vendor/autoload.php';
$app = require $argv[1] . '/bootstrap/app.php';
$app->boot();
$server = json_decode($argv[3], true);
$r = (new Cast\Http\Kernel($app))->handle(new Cast\Http\Request('GET', $argv[2], [], '', $server));
echo $r->statusCode(), "\n", $r->body();
PHP;
    $server = [];
    foreach ($headers as $name => $value) $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
    $file = $dir . '/_run.php';
    file_put_contents($file, "<?php\n" . $script);
    exec(sprintf('php %s %s %s %s 2>&1', escapeshellarg($file), escapeshellarg($dir), escapeshellarg($uri), escapeshellarg(json_encode($server))), $lines);
    unlink($file);
    $out = implode("\n", array_filter($lines, fn($l) => !str_starts_with($l, 'fatal:')));
    $status = (int) strtok($out, "\n");
    return [$status, (string) substr($out, strlen((string) $status) + 1)];
}

test('init: creates a working minimal app, adds the App\\ autoload and names it after the folder', function () {
    $dir = init_dir();
    [$code, $out] = cast_in($dir, 'init');
    eq(0, $code, $out);
    foreach (['public/index.php', 'public/.htaccess', 'bootstrap/app.php', 'config/app.php', 'routes/web.php', 'app/Controllers/HomeController.php',
        'resources/views/layouts/header.cast.php', 'resources/views/home/partials/home.cast.php', 'resources/css/app.css', '.env', 'storage/.gitkeep'] as $file) {
        ok(is_file("$dir/$file"), "$file was created");
        has($file, $out);
    }
    ok(!str_contains(file_get_contents("$dir/public/index.php"), '.stub'));
    ok(is_file("$dir/cast") && is_executable("$dir/cast"), 'the cast launcher exists and is executable');
    has('cast', $out);
    has('APP_NAME="', file_get_contents("$dir/.env"));
    has('composer.json', $out);
    eq('app/', json_decode(file_get_contents("$dir/composer.json"), true)['autoload']['psr-4']['App\\']);

    $ignore = file_get_contents("$dir/.gitignore");
    foreach (['/vendor/', '.env', '/storage/*', '!/storage/.gitkeep'] as $line) has($line, $ignore);

    // the generated app answers: the lazy shell for a browser, the content for the Cast client, 404 for the rest
    [$status, $html] = app_get($dir, '/');
    eq(200, $status, $html);
    has('<title>', $html);
    has('data-cast-lazy', $html);
    has('/cast/cast.module.js', $html);
    [$status, $json] = app_get($dir, '/', ['X-Cast-Request' => '1']);
    eq(200, $status);
    has('It works', json_decode($json, true)['data']['html']);
    eq(404, app_get($dir, '/nope')[0]);
});

test('init: the app name comes from the folder, and existing files are kept unless --force', function () {
    $dir = init_dir();
    $named = dirname($dir) . '/blue-sky_shop';
    rename($dir, $named);
    $GLOBALS['tmp_dirs'][] = $named;
    cast_in($named, 'init');
    has('APP_NAME="Blue Sky Shop"', file_get_contents("$named/.env"));

    file_put_contents("$named/routes/web.php", "<?php // mine\n");
    file_put_contents("$named/.env", "APP_NAME=\"Mine\"\n");
    [$code, $out] = cast_in($named, 'init');
    eq(0, $code);
    has('exists   routes/web.php', $out);
    eq("<?php // mine\n", file_get_contents("$named/routes/web.php"));
    eq("APP_NAME=\"Mine\"\n", file_get_contents("$named/.env"));
    lacks('created  routes/web.php', $out);

    [, $out] = cast_in($named, 'init --force');
    has('created  routes/web.php', $out);
    eq("APP_NAME=\"Mine\"\n", file_get_contents("$named/.env"), '--force never overwrites .env');
    has('init never overwrites .env', $out);
    has('use Cast\Core\Router;', file_get_contents("$named/routes/web.php"));
});

test('init: .gitignore lines are added once, and an existing .gitignore is extended', function () {
    $dir = init_dir();
    file_put_contents("$dir/.gitignore", "node_modules/\n.env\n");
    cast_in($dir, 'init');
    cast_in($dir, 'init');
    $lines = explode("\n", trim(file_get_contents("$dir/.gitignore")));
    eq(1, count(array_keys($lines, 'node_modules/')));
    eq(1, count(array_keys($lines, '.env')), '.env was already listed');
    eq(1, count(array_keys($lines, '/vendor/')));
});

test('init: without a composer.json it says how to add the autoload', function () {
    $dir = init_dir(false);
    [$code, $out] = cast_in($dir, 'init');
    eq(0, $code);
    has('No composer.json found', $out);
});

test('init --demo: copies the starter app (login, items, API) without per-install files', function () {
    $dir = init_dir();
    [$code, $out] = cast_in($dir, 'init --demo');
    eq(0, $code, $out);
    foreach (['app/Controllers/ItemsController.php', 'app/Controllers/Api/ItemsController.php', 'routes/api.php', 'resources/views/items/items.cast.php',
        'resources/js/items/items.module.js', 'config/auth.php', '.env', '.env.example'] as $file) ok(is_file("$dir/$file"), "$file copied");
    ok(!is_file("$dir/composer.lock"));
    eq(1, preg_match('/"name"\s*:\s*"me\/my-shop"/', file_get_contents("$dir/composer.json")), 'composer.json is the app\'s own');
    ok(!is_dir("$dir/node_modules"));
    has('admin@example.com', $out);
    has('DB_NAME=storage/database.sqlite', file_get_contents("$dir/.env"));

    // init migrated and seeded the demo's SQLite database
    has('3 migrations ran.', $out);
    has('Seeded: Database\\Seeders\\DatabaseSeeder', $out);
    ok(is_file("$dir/storage/database.sqlite"), 'the database was created in storage/');
    [, $status] = cast_in($dir, 'migrate:status');
    eq(3, substr_count($status, 'Yes'));

    [$status, $html] = app_get($dir, '/');
    eq(200, $status, $html);
    has('Welcome', json_decode(app_get($dir, '/', ['X-Cast-Request' => '1'])[1], true)['data']['html']);
    [$status] = app_get($dir, '/items');
    eq(302, $status, 'guests are sent to the login page');
});

test('init --demo --no-migrate leaves the database alone; `cast migrate --seed` does it later', function () {
    $dir = init_dir();
    [$code, $out] = cast_in($dir, 'init --demo --no-migrate');
    eq(0, $code, $out);
    lacks('migrations ran', $out);
    ok(!is_file("$dir/storage/database.sqlite"));
    [$code, $out] = cast_in($dir, 'migrate --seed');
    eq(0, $code, $out);
    ok(is_file("$dir/storage/database.sqlite"));
    [$status] = app_get($dir, '/items');
    eq(302, $status);
});

test('php cast <command>: the launcher runs the console of its own app, from any folder', function () {
    $dir = init_dir();
    cast_in($dir, 'init');

    exec(sprintf('cd %s && php cast route:list 2>&1', escapeshellarg($dir)), $lines);
    $out = implode("\n", $lines);
    has('App\\Controllers\\HomeController@index', $out);

    // from another folder, it still uses the app that contains it
    exec(sprintf('cd / && php %s version 2>&1', escapeshellarg("$dir/cast")), $lines2);
    has('CastFramework', implode("\n", $lines2));

    exec(sprintf('cd %s && php cast nope 2>&1', escapeshellarg($dir)), $lines3, $code);
    eq(1, $code);
    has('not defined', implode("\n", $lines3));
});

test('init: an app made before the launcher existed gets `cast` on the next init, without touching its other files', function () {
    $dir = init_dir();
    cast_in($dir, 'init');
    unlink("$dir/cast");
    file_put_contents("$dir/routes/web.php", "<?php // mine\n");
    [$code, $out] = cast_in($dir, 'init');
    eq(0, $code);
    ok(is_file("$dir/cast"));
    eq("<?php // mine\n", file_get_contents("$dir/routes/web.php"));
});

test('init --demo: the starter has the cast launcher too', function () {
    $dir = init_dir();
    cast_in($dir, 'init --demo');
    ok(is_executable("$dir/cast"));
    exec(sprintf('cd %s && php cast route:list 2>&1', escapeshellarg($dir)), $lines);
    has('/api/items', implode("\n", $lines));
});

// ------------------------------------------------------------ editor:install

/** @return array{int, string} */
function editor_install(string $home, string $args): array
{
    $dir = init_dir();
    exec(sprintf('cd %s && CAST_HOME=%s php %s editor:install %s 2>&1', escapeshellarg($dir), escapeshellarg($home), escapeshellarg(dirname(__DIR__, 2) . '/bin/cast'), $args), $lines, $code);
    return [$code, implode("\n", array_filter($lines, fn($l) => !str_starts_with($l, 'fatal:')))];
}

function fake_home(array $editors): string
{
    $home = app_dir();
    foreach ($editors as $dir) mkdir("$home/$dir", 0777, true);
    return $home;
}

test('editor:install copies the extension into every VS Code style editor found, without tests or node_modules', function () {
    $home = fake_home(['.vscode/extensions', '.cursor/extensions']);
    [$code, $out] = editor_install($home, '');
    eq(0, $code, $out);
    has('installed code', $out);
    has('installed cursor', $out);
    lacks('insiders', $out);

    $version = json_decode(file_get_contents(dirname(__DIR__, 2) . '/editor/vscode/package.json'), true)['version'];
    foreach (['.vscode', '.cursor'] as $editor) {
        $ext = "$home/$editor/extensions/anode.cast-framework-$version";
        foreach (['package.json', 'extension.js', 'lib/scan.js', 'lib/resolve.js', 'syntaxes/cast-injection.tmLanguage.json', 'snippets/cast.code-snippets', 'README.md'] as $file) {
            ok(is_file("$ext/$file"), "$editor: $file");
        }
        ok(!is_dir("$ext/test") && !is_dir("$ext/node_modules") && !is_file("$ext/package-lock.json"), "$editor: no dev files");
    }
});

test('editor:install replaces an older version, and --uninstall removes it', function () {
    $home = fake_home(['.vscode/extensions']);
    mkdir("$home/.vscode/extensions/anode.cast-framework-0.0.1");
    file_put_contents("$home/.vscode/extensions/anode.cast-framework-0.0.1/old.txt", 'old');
    mkdir("$home/.vscode/extensions/someone.else-1.0.0");

    editor_install($home, '');
    ok(!is_dir("$home/.vscode/extensions/anode.cast-framework-0.0.1"), 'the old version is gone');
    ok(is_dir("$home/.vscode/extensions/someone.else-1.0.0"), 'other extensions are untouched');
    eq(1, count(glob("$home/.vscode/extensions/anode.cast-framework-*")));

    [$code, $out] = editor_install($home, '--uninstall');
    eq(0, $code, $out);
    eq([], glob("$home/.vscode/extensions/anode.cast-framework-*"));
    ok(is_dir("$home/.vscode/extensions/someone.else-1.0.0"));
});

test('editor:install: --editor creates the folder, --dir installs anywhere, and problems are explained', function () {
    $home = fake_home([]);
    [$code, $out] = editor_install($home, '');
    eq(1, $code);
    has('No VS Code style editor was found', $out);

    [$code] = editor_install($home, '--editor=insiders');
    eq(0, $code);
    eq(1, count(glob("$home/.vscode-insiders/extensions/anode.cast-framework-*")));

    $custom = app_dir() . '/ext';
    [$code] = editor_install($home, '--dir=' . escapeshellarg($custom));
    eq(0, $code);
    eq(1, count(glob("$custom/anode.cast-framework-*")));

    [$code, $out] = editor_install($home, '--editor=notepad');
    eq(1, $code);
    has('Unknown editor', $out);
});

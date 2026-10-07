<?php

declare(strict_types=1);

use Cast\Console\{Input, Output};
use Cast\Console\Commands\InitCommand;
use Cast\Services\ErrorProvider;

/** init, asked questions in-process: $answers is what the user types, one answer per line. @return array{string, string} app folder, screen */
function init_asked(string $answers, array $args = []): array
{
    $app = boot_app(['composer.json' => json_encode(['name' => 'me/shop', 'require' => new stdClass()])]);
    $screen = fopen('php://memory', 'w+');
    $stdin = fopen('php://memory', 'w+');
    fwrite($stdin, $answers);
    rewind($stdin);
    $cmd = new InitCommand($app);
    $cmd->stdin = $stdin;
    $code = $cmd->handle(new Input(['init', ...$args]), new Output($screen));
    rewind($screen);
    $out = (string) stream_get_contents($screen);
    eq(0, $code, $out);
    return [$app->basePath(), $out];
}

test('init asks about the source folder, the front end, the error handler and the error pages', function () {
    // source=app/Core, front end 2 (php), error handler yes, configure yes, ... custom error pages (2)
    $answers = implode("\n", [
        'lib',            // source folder
        '2',              // front end: php
        'y',              // use the error handler
        'y',              // configure it
        'n',              // log errors? no
        'var/log',        // log folder
        'y',              // developer logs
        'var/dev',        // developer log folder
        'y',              // display_errors
        'y',              // email errors
        'ops@example.com',
        'Oops',           // subject
        '2',              // error pages: custom
    ]) . "\n";
    [$dir, $screen] = init_asked($answers);

    has('Folder for your app source code', $screen);
    has('How will the front end work?', $screen);
    has('The error handler\'s settings and their defaults', $screen);
    has('storage/logs/', $screen, 'the defaults are listed');
    has('E_ALL', $screen);

    ok(is_file("$dir/lib/Controllers/HomeController.php"), 'source folder');
    $config = (string) file_get_contents("$dir/config/app.php");
    has("'source_path' => 'lib'", $config);
    has("'log_errors' => false", $config);
    has("'log_directory' => 'var/log/'", $config);
    has("'dev_logs' => true", $config);
    has("'dev_logs_directory' => 'var/dev/'", $config);
    has("'display_errors' => true", $config);
    has("'email_logging_address' => 'ops@example.com'", $config);
    has("'error_pages' => 'custom'", $config);
    eq('lib/', json_decode((string) file_get_contents("$dir/composer.json"), true)['autoload']['psr-4']['App\\']);
    lacks("'spa'", (string) file_get_contents("$dir/lib/Controllers/HomeController.php"));
    lacks('__cast(', (string) file_get_contents("$dir/resources/views/layouts/header.cast.php"));
    foreach ([403, 404, 405, 419, 500, 503] as $code) {
        ok(is_file("$dir/resources/views/errors/$code.cast.php") && filesize("$dir/resources/views/errors/$code.cast.php") === 0, "an empty errors/$code view");
    }
});

test('init: pressing Enter on every question gives src, the SPA client, the error handler on and the framework error pages', function () {
    [$dir, $screen] = init_asked(str_repeat("\n", 12));
    ok(is_file("$dir/src/Controllers/HomeController.php"));
    $config = (string) file_get_contents("$dir/config/app.php");
    has("'source_path' => 'src'", $config);
    lacks('error_handler', $config);
    lacks('error_pages', $config);
    has("'spa' => true", (string) file_get_contents("$dir/src/Controllers/HomeController.php"));
    has('__cast(', (string) file_get_contents("$dir/resources/views/layouts/header.cast.php"));
    ok(!is_dir("$dir/resources/views/errors"));
});

test('init --frontend=external: an API for a front-end framework, with CORS and no views', function () {
    $dir = init_dir();
    [$code, $out] = cast_in($dir, 'init -n --frontend=external --cors=http://localhost:3000');
    eq(0, $code, $out);
    ok(is_file("$dir/src/Controllers/StatusController.php"));
    ok(is_file("$dir/routes/api.php"));
    ok(!is_file("$dir/routes/web.php") && !is_dir("$dir/resources") && !is_file("$dir/src/Controllers/HomeController.php"), 'no web files');
    has('CORS_ALLOWED_ORIGINS=http://localhost:3000', (string) file_get_contents("$dir/.env"));
    has('php cast token:create', $out);
    [$status, $body] = app_get($dir, '/api/status');
    eq(200, $status, $body);
    $json = json_decode($body, true);
    eq('success', $json['status'] ?? null, $body);
    [$status, $body] = app_get($dir, '/api/nope');
    eq(404, $status);
    eq('error', json_decode($body, true)['status'] ?? null, 'errors are JSON');
});

test('init --frontend=api (no front end) and --source=app keep the classic layout', function () {
    $dir = init_dir();
    [$code, $out] = cast_in($dir, 'init -n --frontend=api --source=app');
    eq(0, $code, $out);
    ok(is_file("$dir/app/Controllers/StatusController.php"));
    eq('app/', json_decode((string) file_get_contents("$dir/composer.json"), true)['autoload']['psr-4']['App\\']);
    has("'source_path' => 'app'", (string) file_get_contents("$dir/config/app.php"));
});

test('init rejects an unsafe source folder and an unknown choice', function () {
    $dir = init_dir();
    [$code, $out] = cast_in($dir, 'init -n --source=../outside');
    eq(1, $code);
    has('simple relative path', $out);
    [$code, $out] = cast_in($dir, 'init -n --frontend=vue');
    eq(1, $code);
    has('--frontend must be one of', $out);
    ok(!is_dir("$dir/src") && !is_dir("$dir/../outside"));
});

test('init --demo --source=lib moves the starter\'s classes and the helper folder', function () {
    $dir = init_dir();
    [$code, $out] = cast_in($dir, 'init --demo --no-migrate --source=lib -n --error-pages=custom');
    eq(0, $code, $out);
    ok(is_file("$dir/lib/Controllers/ItemsController.php") && !is_dir("$dir/app"));
    has("'lib/helpers'", (string) file_get_contents("$dir/config/helpers.php"));
    $config = (string) file_get_contents("$dir/config/app.php");
    has("'source_path' => 'lib'", $config);
    has('App\Providers\AppServiceProvider::class', $config);
    [$code, $out] = cast_in($dir, 'migrate --seed');
    eq(0, $code, $out);
    [$status, $html] = app_get($dir, '/');
    eq(200, $status, $html);
});

test('custom error pages: empty views fall back to the framework page, with a note in development only; a built view is used', function () {
    $dir = init_dir();
    cast_in($dir, 'init -n --error-pages=custom');
    $env = (string) file_get_contents("$dir/.env");

    [$status, $html] = app_get($dir, '/no-such-page');
    eq(404, $status);
    has('Page Not Found', $html);
    has('You configured the framework to use your own error pages', $html);
    has('resources/views/errors/404.cast.php is empty', $html);

    file_put_contents("$dir/.env", preg_replace(['/APP_ENV=.*/', '/APP_DEBUG=.*/'], ['APP_ENV=production', 'APP_DEBUG=false'], $env));
    [$status, $html] = app_get($dir, '/no-such-page');
    eq(404, $status);
    has('Page Not Found', $html);
    lacks('You configured', $html);

    file_put_contents("$dir/resources/views/errors/404.cast.php", '<h1>Nothing here, sorry</h1>');
    [$status, $html] = app_get($dir, '/no-such-page');
    eq(404, $status);
    has('Nothing here, sorry', $html);

    // a missing file behaves like an empty one
    unlink("$dir/resources/views/errors/403.cast.php");
    file_put_contents("$dir/.env", $env);
    file_put_contents("$dir/routes/web.php", (string) file_get_contents("$dir/routes/web.php") . "\nCast\\Core\\Router::get('/secret', fn() => abort(403));\n");
    [$status, $html] = app_get($dir, '/secret');
    eq(403, $status);
    has('resources/views/errors/403.cast.php does not exist', $html);
});

test('app.error_handler can be true, false or an options array', function () {
    $app = boot_app(['config/app.php' => "<?php return ['error_handler' => ['log_errors' => false, 'log_directory' => 'var/log/', 'enabled' => true]];"]);
    $options = ErrorProvider::options($app);
    eq(false, $options['log_errors']);
    ok(str_starts_with($options['log_directory'], $app->basePath()) && str_ends_with($options['log_directory'], 'var/log' . DIRECTORY_SEPARATOR), 'folders are relative to the app: ' . $options['log_directory']);
    ok(!array_key_exists('enabled', $options), 'the switch is not passed to the package');
    eq('Test', $options['app_name'], 'the rest still comes from the app settings');
});

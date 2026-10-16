<?php

declare(strict_types=1);

use Cast\App\Application;
use Cast\Core\Config;
use Cast\Core\Database;
use Cast\Core\Env;
use Cast\Core\Router;
use Cast\Http\Request;
use Cast\Validation\Validator;

// the framework logs server errors with error_log(); keep that out of the test output
ini_set('error_log', sys_get_temp_dir() . '/cast-tests-error.log');

$GLOBALS['failures'] = 0;
$GLOBALS['total'] = 0;
$GLOBALS['tmp_dirs'] = [];

register_shutdown_function(function () {
    foreach ($GLOBALS['tmp_dirs'] as $dir) {
        if (!is_dir($dir)) continue;
        if (DIRECTORY_SEPARATOR === '\\') {
            exec('rmdir /s /q ' . escapeshellarg($dir));
        } else {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }
});

if (!function_exists('init_dir')) {
    function init_dir(bool $composerJson = true): string
    {
        $dir = app_dir($composerJson ? ['composer.json' => json_encode(['name' => 'me/my-shop', 'require' => new stdClass()])] : []);
        $framework = dirname(__DIR__);
        mkdir("$dir/vendor", 0777, true);
        file_put_contents("$dir/vendor/autoload.php", "<?php\nrequire " . var_export($framework . '/vendor/autoload.php', true) . ";\n");
        return $dir;
    }
}

if (!function_exists('cast_in')) {
    function cast_in(string $dir, string $args): array
    {
        $framework = dirname(__DIR__);
        $cd = DIRECTORY_SEPARATOR === '\\' ? 'cd /d' : 'cd';
        $cmd = sprintf('%s %s && php %s %s 2>&1', $cd, escapeshellarg($dir), escapeshellarg($framework . '/bin/cast'), $args);
        exec($cmd, $lines, $code);
        return [$code, implode("\n", array_filter($lines, fn($l) => !str_starts_with($l, 'fatal:')))];
    }
}

if (!function_exists('cast')) {
    function cast(array $argv, ?Application $app = null): array
    {
        $app ??= Application::instance() ?? boot_app([], boot: false);
        $stream = fopen('php://memory', 'w+');
        $code = (new \Cast\Console\Kernel($app, new \Cast\Console\Output($stream)))->run($argv);
        rewind($stream);
        return [$code, stream_get_contents($stream)];
    }
}

if (!function_exists('console_app')) {
    function console_app(array $files = [], string $env = 'development'): Application
    {
        $app = boot_app($files + ['.env' => "APP_ENV=$env\nAPP_DEBUG=false\n"]);
        sqlite();
        return $app;
    }
}

if (!function_exists('starter')) {
    function starter(bool $lazy = false, ?string $pack = null): Application
    {
        $source = dirname(__DIR__) . '/starter';
        $dir = app_dir();
        $cd = DIRECTORY_SEPARATOR === '\\' ? 'cd /d' : 'cd';
        exec(sprintf('%s %s && tar --exclude=./vendor --exclude=./storage --exclude=./.env -cf - . | tar -xf - -C %s', $cd, escapeshellarg($source), escapeshellarg($dir)));
        file_put_contents("$dir/.env", "APP_NAME=\"Cast Starter\"\nAPP_ENV=development\nAPP_DEBUG=true\nDB_CONN=sqlite\nDB_NAME=:memory:\n");
        mkdir("$dir/storage", 0777, true);

        static $registered = false;
        if (!$registered) {
            spl_autoload_register(function (string $class) {
                if (str_starts_with($class, 'App\\')) {
                    $file = ($GLOBALS['starter_dir'] ?? '') . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
                    if (is_file($file)) require $file;
                }
            });
            $registered = true;
        }
        $GLOBALS['starter_dir'] = $dir;

        if ($pack !== null) {
            $script = "$dir/.strip.php";
            file_put_contents($script, '<?php require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ';'
                . '$app = new Cast\\App\\Application(' . var_export($dir, true) . '); $app->boot();'
                . 'exit((new Cast\\Console\\Commands\\DemoStripCommand($app))->handle(new Cast\\Console\\Input(["demo:strip", "--pack=' . $pack . '", "--yes"]), new Cast\\Console\\Output(fopen("php://memory", "w+"))));');
            exec('php ' . escapeshellarg($script) . ' 2>&1', $out, $code);
            eq(0, $code, implode("\n", $out));
            unlink($script);
        }
        $app = require "$dir/bootstrap/app.php";
        $app->boot();
        $app->make('migrator')->migrate();
        if ($pack === null || str_starts_with($pack, 'auth')) \Cast\Database\SeederRunner::run('UserSeeder');
        if (!$lazy) \Cast\Core\Config::set('spa.initial', 'inline');
        return $app;
    }
}

if (!function_exists('form')) {
    function form(string $method, string $uri, array $fields = []): Request
    {
        $fields += ['_token' => \Cast\Core\Session::csrfToken()];
        return new Request($method, $uri, [], http_build_query($fields), ['CONTENT_TYPE' => 'application/x-www-form-urlencoded']);
    }
}

if (!function_exists('api')) {
    function api(string $method, string $uri, array $body = []): Request
    {
        return req($method, $uri, $body, ['HTTP_X_CSRF_TOKEN' => \Cast\Core\Session::csrfToken(), 'HTTP_ACCEPT' => 'application/json']);
    }
}

if (!function_exists('login_admin')) {
    function login_admin(): void
    {
        $r = handle(form('POST', '/login', ['email' => 'admin@example.com', 'password' => 'password']));
        eq(302, $r->statusCode());
    }
}

/**
 * Register a test case. With `$GLOBALS['cast_collect']` set (the PHPUnit bridge) the case is collected and run later by
 * PHPUnit; otherwise (tests/run.php) it runs right away and prints its result.
 */
function test(string $name, callable $fn): void
{
    if (isset($GLOBALS['cast_collect'])) {
        $GLOBALS['cast_collect'][] = [(string) ($GLOBALS['cast_file'] ?? ''), $name, $fn];
        return;
    }

    $GLOBALS['total']++;
    reset_state();
    try {
        $fn();
        echo "  ok    $name\n";
    } catch (\Throwable $e) {
        $GLOBALS['failures']++;
        $GLOBALS['failed'][] = ($GLOBALS['cast_file'] ?? '') . ': ' . $name;
        echo "  FAIL  $name\n        " . str_replace("\n", "\n        ", $e->getMessage()) . "\n";
        if (!$e instanceof AssertionError) echo '        at ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    }
}

function eq(mixed $expected, mixed $actual, string $what = ''): void
{
    if ($expected !== $actual) {
        throw new AssertionError(($what !== '' ? "$what: " : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function ok(mixed $condition, string $what = 'condition'): void
{
    if (!$condition) throw new AssertionError("expected $what to be true");
}

function has(string $needle, string $haystack, string $what = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new AssertionError(($what !== '' ? "$what: " : '') . "expected to find \"$needle\" in: " . substr(preg_replace('/\s+/', ' ', $haystack), 0, 200));
    }
}

function lacks(string $needle, string $haystack, string $what = ''): void
{
    if (str_contains($haystack, $needle)) {
        throw new AssertionError(($what !== '' ? "$what: " : '') . "did not expect \"$needle\" in output");
    }
}

/** @param class-string<Throwable> $class */
function throws(string $class, callable $fn, string $contains = ''): Throwable
{
    try {
        $fn();
    } catch (\Throwable $e) {
        if (!$e instanceof $class) throw new AssertionError("expected $class, got " . $e::class . ': ' . $e->getMessage());
        if ($contains !== '' && !str_contains($e->getMessage(), $contains)) {
            throw new AssertionError("expected message containing \"$contains\", got \"{$e->getMessage()}\"");
        }
        return $e;
    }
    throw new AssertionError("expected $class to be thrown");
}

/** Forget everything the previous test left behind. */
function reset_state(): void
{
    Application::forget();
    Env::reset();
    Config::reset();
    Router::flush();
    Database::reset();
    Request::setCurrent(null);
    Validator::forgetExtensions();
    $_SESSION = [];
    $_GET = $_POST = $_COOKIE = [];
    foreach (['REQUEST_METHOD', 'REQUEST_URI'] as $k) unset($_SERVER[$k]);
}

/** A temporary app folder with the given files (relative path => contents). */
function app_dir(array $files = []): string
{
    $dir = sys_get_temp_dir() . '/cast-test-' . bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);
    $GLOBALS['tmp_dirs'][] = $dir;
    foreach ($files as $path => $content) {
        $full = "$dir/$path";
        if (!is_dir(dirname($full))) mkdir(dirname($full), 0777, true);
        file_put_contents($full, $content);
    }
    return $dir;
}

/** Create and boot an application in a temp folder. */
function boot_app(array $files = [], array $options = [], bool $boot = true): Application
{
    $files += ['.env' => "APP_NAME=Test\nAPP_ENV=development\nAPP_DEBUG=true\n"];
    $app = new Application(app_dir($files), $options);
    if ($boot) $app->boot();
    return $app;
}

function sqlite(string $schema = ''): PDO
{
    $pdo = Database::connect(['driver' => 'sqlite', 'name' => ':memory:']);
    if ($schema !== '') $pdo->exec($schema);
    Database::use($pdo);
    return $pdo;
}

function handle(Request $request): \Cast\Http\Response
{
    return (new \Cast\Http\Kernel(Application::instance()))->handle($request);
}

function req(string $method, string $uri, array $body = [], array $server = [], array $query = []): Request
{
    $json = $body ? json_encode($body) : '';
    return new Request($method, $uri, $query, $json, $server + ($json ? ['CONTENT_TYPE' => 'application/json'] : []));
}

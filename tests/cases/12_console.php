<?php

declare(strict_types=1);

use Cast\App\Application;
use Cast\Console\Command;
use Cast\Console\Input;
use Cast\Console\Kernel;
use Cast\Console\Output;
use Cast\Core\Router;

/** @return array{int, string} exit code and output */
function cast(array $argv, ?Application $app = null): array
{
    $app ??= Application::instance() ?? boot_app(boot: false);
    $stream = fopen('php://memory', 'w+');
    $code = (new Kernel($app, new Output($stream)))->run($argv);
    rewind($stream);
    return [$code, stream_get_contents($stream)];
}

class SayHelloCommand extends Command
{
    protected string $name = 'say:hello';
    protected string $description = 'Says hello';
    public function handle(Input $input, Output $output): int
    {
        $output->info('Hello ' . ($input->argument(0) ?? 'world') . ($input->hasOption('loud') ? '!' : ''));
        return (int) $input->option('code', 0);
    }
}

test('Input: command, arguments, long and short options', function () {
    $i = new Input(['make:thing', 'Name', 'other', '--force', '--path=app/x', '-vq', '--empty=']);
    eq('make:thing', $i->command());
    eq('Name', $i->argument(0));
    eq('other', $i->argument(1));
    eq('d', $i->argument(5, 'd'));
    eq(['Name', 'other'], $i->arguments());
    ok($i->hasOption('force') && $i->hasOption('v') && $i->hasOption('q') && !$i->hasOption('nope'));
    eq(true, $i->option('force'));
    eq('app/x', $i->option('path'));
    eq('', $i->option('empty'));
    eq('dflt', $i->option('nope', 'dflt'));
    eq('', (new Input([]))->command());
});

test('Output: line, info/warn/error, aligned table', function () {
    $stream = fopen('php://memory', 'w+');
    $o = new Output($stream);
    $o->line('plain');
    $o->info('ok');
    $o->warn('careful');
    $o->error('bad');
    $o->table(['Name', 'Qty'], [['bolt', '10'], ['a-long-name', '5']]);
    rewind($stream);
    $out = stream_get_contents($stream);
    eq("plain\nok\ncareful\nbad\nName         Qty\n-----------  ---\nbolt         10\na-long-name  5\n", $out);
});

test('Console: list shows every built-in command; unknown commands fail with a suggestion', function () {
    [$code, $out] = cast([]);
    eq(0, $code);
    foreach (['serve', 'route:list', 'views:clear', 'down', 'up', 'env:check', 'version', 'make:controller', 'make:model', 'make:middleware', 'make:command', 'make:rule'] as $name) {
        has($name, $out, $name);
    }
    has('Usage: php cast', $out);
    [$code2, $out2] = cast(['list']);
    eq($out, $out2);
    eq($out, cast(['help'])[1]);

    [$code, $out] = cast(['make:nothing']);
    eq(1, $code);
    has('Command "make:nothing" is not defined.', $out);
    has('Did you mean: make:', $out);
    eq(1, cast(['zzz'])[0]);
});

test('Console: version', function () {
    [$code, $out] = cast(['version']);
    eq(0, $code);
    has('CastFramework ' . Application::VERSION, $out);
    has('PHP ' . PHP_VERSION, $out);
});

test('Console: app commands from config(console.commands) run with arguments, options and exit codes', function () {
    $app = boot_app(['config/console.php' => "<?php return ['commands' => [SayHelloCommand::class]];"], boot: false);
    [$code, $out] = cast(['say:hello', 'Ann', '--loud'], $app);
    eq(0, $code);
    has('Hello Ann!', $out);
    eq(3, cast(['say:hello', '--code=3'], $app)[0]);
    has('say:hello', cast(['list'], $app)[1]);
    has('Says hello', cast(['list'], $app)[1]);
});

test('Console: route:list shows verbs, paths, handlers and middleware', function () {
    $app = boot_app(['routes/web.php' => <<<'PHP'
<?php
use Cast\Core\Router;
use Cast\Http\Middleware\Authenticate;
Router::get('/', fn() => 'x');
Router::middleware([Authenticate::class, 'private'], function () {
    Router::group('/items', function () {
        Router::get('/', [ItemsController::class, 'index']);
        Router::put('/{id}', [ItemsController::class, 'update'])->middleware(['edit-items']);
        Router::delete('/{id}', 'ItemsController::destroy');
        Router::get('/all', ItemsController::class);
    });
});
PHP], boot: false);
    [$code, $out] = cast(['route:list'], $app);
    eq(0, $code);
    foreach (['Method', 'Path', 'Handler', 'Middleware', '/items/{id}', 'ItemsController@index', 'ItemsController@update', 'ItemsController@destroy', 'ItemsController@index', 'Closure', 'Authenticate:private', 'can:edit-items'] as $needle) {
        has($needle, $out, $needle);
    }
    has('PUT', $out);
    has('DELETE', $out);
});

test('Console: route:list on an app without routes says so', function () {
    $app = boot_app(boot: false);
    [$code, $out] = cast(['route:list'], $app);
    eq(0, $code);
    has('No routes are registered.', $out);
});

test('Console: views:clear deletes compiled views', function () {
    $app = boot_app(['resources/views/a.cast.php' => 'a', 'resources/views/b.cast.php' => 'b']);
    $view = $app->make('view');
    $view->render('a');
    $view->render('b');
    $before = count(glob($app->storagePath('framework/views/*.php')));
    ok($before >= 2);
    [$code, $out] = cast(['views:clear'], $app);
    eq(0, $code);
    has("Removed $before compiled views.", $out);
    eq([], glob($app->storagePath('framework/views/*.php')));
    has('Removed 0 compiled views.', cast(['views:clear'], $app)[1]);
});

test('Console: down / up toggle maintenance mode (with message, secret and countdown)', function () {
    $app = boot_app();
    [$code, $out] = cast(['down', '--message=Back soon', '--secret=abc', '--retry=90'], $app);
    eq(0, $code);
    has('maintenance mode', $out);
    has('X-Maintenance-Secret', $out);
    $status = $app->make('maintenance')->status();
    ok($status['active']);
    eq('Back soon', $status['message']);
    eq(90, $status['retry_after']);

    [$code, $out] = cast(['up'], $app);
    eq(0, $code);
    ok(!$app->make('maintenance')->status()['active']);
    has('now live', $out);

    cast(['down', '--in=120'], $app);
    $status = $app->make('maintenance')->status();
    ok($status['scheduled'] && !$status['active'] && $status['seconds_remaining'] > 100);
    has('scheduled in 120 seconds', cast(['down', '--in=120'], $app)[1]);
});

test('Console: env:check passes on a healthy app and fails on common problems', function () {
    $app = boot_app(['views_placeholder' => '', 'resources/views/.keep' => '', 'routes/.keep' => ''], boot: false);
    [$code, $out] = cast(['env:check'], $app);
    eq(0, $code, $out);
    has('Everything looks good.', $out);
    has('ok    PHP 8.1 or newer', $out);

    $bad = boot_app(['.env' => "APP_ENV=production\nAPP_DEBUG=true\n"], boot: false);
    unlink($bad->basePath('.env'));
    [$code, $out] = cast(['env:check'], $bad);
    eq(1, $code);
    has('FAIL  .env file exists', $out);
    has('FAIL  views folder exists', $out);
    has('problem(s) found.', $out);

    $prod = boot_app(['.env' => "APP_NAME=X\nAPP_ENV=production\nAPP_DEBUG=true\n", 'resources/views/.keep' => '', 'routes/.keep' => ''], boot: false);
    [$code, $out] = cast(['env:check'], $prod);
    eq(1, $code);
    has('FAIL  debug is off in production (set APP_DEBUG=false)', $out);
});

test('Console: serve --dry prints the PHP built-in server command', function () {
    $app = boot_app(boot: false);
    [$code, $out] = cast(['serve', '--dry'], $app);
    eq(0, $code);
    has('-S 127.0.0.1:8000', $out);
    has($app->publicPath(), $out);
    has('index.php', $out);
    has('-S 0.0.0.0:9000', cast(['serve', '--dry', '--host=0.0.0.0', '--port=9000'], $app)[1]);
});

test('make:*: creates valid classes with the right namespace, folder and name', function () {
    $app = boot_app(boot: false);
    $cases = [
        ['make:controller', 'Invoice', 'app/Controllers/InvoiceController.php', 'App\\Controllers', 'InvoiceController', 'extends Controller'],
        ['make:controller', 'InvoiceController', 'app/Controllers/InvoiceController.php', 'App\\Controllers', 'InvoiceController', 'extends Controller'],
        ['make:model', 'JournalEntries', 'app/Models/JournalEntries.php', 'App\\Models', 'JournalEntries', 'extends Model'],
        ['make:middleware', 'EnsureAdmin', 'app/Middleware/EnsureAdmin.php', 'App\\Middleware', 'EnsureAdmin', 'implements Middleware'],
        ['make:command', 'SyncStock', 'app/Console/Commands/SyncStockCommand.php', 'App\\Console\\Commands', 'SyncStockCommand', 'extends Command'],
        ['make:rule', 'Uppercase', 'app/Rules/Uppercase.php', 'App\\Rules', 'Uppercase', 'implements Rule'],
    ];
    foreach ($cases as [$command, $name, $file, $namespace, $class, $needle]) {
        [$code, $out] = cast([$command, $name], $app);
        eq(0, $code, "$command $name: $out");
        $path = $app->basePath($file);
        ok(is_file($path), "$file created");
        $source = file_get_contents($path);
        has("namespace $namespace;", $source);
        has("class $class ", $source);
        has($needle, $source);
        has("Created $namespace\\$class", $out);
        exec('php -l ' . escapeshellarg($path) . ' 2>&1', $lint, $status);
        eq(0, $status, "$file is valid PHP: " . implode(' ', $lint));
        unset($lint);
        unlink($path);
    }
});

test('make:*: stubs carry sensible defaults (view name, table name, command name)', function () {
    $app = boot_app(boot: false);
    cast(['make:controller', 'SalesReport'], $app);
    has("'sales-report'", file_get_contents($app->basePath('app/Controllers/SalesReportController.php')));
    cast(['make:model', 'JournalEntries'], $app);
    has('"journal_entries"', file_get_contents($app->basePath('app/Models/JournalEntries.php')));
    cast(['make:command', 'SyncStock'], $app);
    has("'sync:stock'", file_get_contents($app->basePath('app/Console/Commands/SyncStockCommand.php')));
});

test('make:*: sub-folders, custom root namespace/path, overwrite protection, bad names', function () {
    $app = boot_app(['config/app.php' => "<?php return ['namespace' => 'Acme', 'source_path' => 'src'];"], boot: false);
    [$code] = cast(['make:controller', 'admin/user'], $app);
    eq(0, $code);
    $path = $app->basePath('src/Controllers/Admin/UserController.php');
    ok(is_file($path));
    has('namespace Acme\\Controllers\\Admin;', file_get_contents($path));

    file_put_contents($path, '<?php // mine');
    [$code, $out] = cast(['make:controller', 'admin/user'], $app);
    eq(1, $code);
    has('already exists', $out);
    eq('<?php // mine', file_get_contents($path), 'not overwritten');
    eq(0, cast(['make:controller', 'admin/user', '--force'], $app)[0]);
    has('class UserController', file_get_contents($path));

    foreach (['', '../../etc/passwd', 'Bad Name', '1abc', 'a..b'] as $bad) {
        [$code, $out] = $bad === '' ? cast(['make:model'], $app) : cast(['make:model', $bad], $app);
        eq(1, $code, "name '$bad'");
        has('Usage: php cast make:model', $out);
    }
    ok(!is_dir($app->basePath('etc')) && !file_exists('/tmp/passwd.php'));
});

test('bin/cast: runs from the command line against an app folder', function () {
    $dir = app_dir(['.env' => "APP_NAME=Cli\n"]);
    $cmd = sprintf('cd %s && %s %s version 2>&1', escapeshellarg($dir), escapeshellarg(PHP_BINARY), escapeshellarg(__DIR__ . '/../../bin/cast'));
    exec($cmd, $lines, $status);
    eq(0, $status, implode("\n", $lines));
    has('CastFramework', implode("\n", $lines));
    exec(sprintf('cd %s && %s %s nope 2>&1', escapeshellarg($dir), escapeshellarg(PHP_BINARY), escapeshellarg(__DIR__ . '/../../bin/cast')), $out2, $status2);
    eq(1, $status2);
});

<?php

declare(strict_types=1);

use Cast\App\Application;
use Cast\Core\Config;
use Cast\Core\Env;

test('Env: parses quotes, comments, export, blanks and casts values', function () {
    $dir = app_dir(['.env' => <<<'ENV'
# a comment
APP_NAME="My App"
export APP_ENV=production   # trailing comment
SINGLE='keep $this # raw'
EMPTY=
FLAG=true
OFF=false
NOTHING=null
BLANK=empty
URL=https://x.test/a?b=c#frag
ESC="line1\nline2"
  SPACED = value with spaces
ENV]);
    Env::load("$dir/.env");
    eq('My App', Env::get('app_name'), 'case-insensitive key + quotes');
    eq('production', Env::get('APP_ENV'), 'export + trailing comment');
    eq('keep $this # raw', Env::get('SINGLE'));
    eq('', Env::get('EMPTY'));
    eq(true, Env::get('FLAG'));
    eq(false, Env::get('OFF'));
    eq(null, Env::get('NOTHING'));
    eq('', Env::get('BLANK'));
    eq('https://x.test/a?b=c#frag', Env::get('URL'), '# inside a value without a space before it');
    eq("line1\nline2", Env::get('ESC'));
    eq('value with spaces', Env::get('SPACED'));
    eq('dflt', Env::get('MISSING', 'dflt'));
});

test('Env: reads the file once (cached) and falls back to real environment variables', function () {
    $dir = app_dir(['.env' => "A=1\n"]);
    Env::load("$dir/.env");
    eq('1', Env::get('A'));
    file_put_contents("$dir/.env", "A=2\n");
    eq('1', Env::get('A'), 'not re-read');
    putenv('CAST_TEST_REAL=from-process');
    eq('from-process', Env::get('CAST_TEST_REAL'));
    putenv('CAST_TEST_REAL');
});

test('Env: bool(), int() and has()', function () {
    $dir = app_dir(['.env' => "D1=false\nD2=0\nD3=off\nD4=yes\nD5=1\nN=42\nBAD=abc\n"]);
    Env::load("$dir/.env");
    eq(false, Env::bool('D1'));
    eq(false, Env::bool('D2'));
    eq(false, Env::bool('D3'));
    eq(true, Env::bool('D4'));
    eq(true, Env::bool('D5'));
    eq(true, Env::bool('NOPE', true));
    eq(42, Env::int('N'));
    eq(7, Env::int('BAD', 7));
    ok(Env::has('N') && !Env::has('NOPE'), 'has()');
});

test('Env::set(): updates memory and rewrites only that line of the file', function () {
    $dir = app_dir(['.env' => "# keep me\nA=1\nB=2\n"]);
    Env::load("$dir/.env");
    Env::set('B', 'two words');
    Env::set('NEW', 5);
    eq('two words', Env::get('B'));
    eq("# keep me\nA=1\nB=\"two words\"\nNEW=5\n", file_get_contents("$dir/.env"));
    throws(InvalidArgumentException::class, fn() => Env::set('BAD=KEY', 'x'));
});

test('Config: dot notation get/set/has, defaults and loading a config folder', function () {
    $dir = app_dir(['config/app.php' => "<?php return ['name' => 'X', 'nested' => ['a' => 1]];", 'config/other.php' => "<?php return ['k' => 'v'];", 'config/notarray.php' => '<?php return 5;']);
    Config::load("$dir/config");
    eq('X', Config::get('app.name'));
    eq(1, Config::get('app.nested.a'));
    eq('v', Config::get('other.k'));
    eq('dflt', Config::get('app.missing', 'dflt'));
    ok(Config::has('app.name') && !Config::has('app.nope') && !Config::has('notarray'), 'has()');
    Config::set('app.nested.b', 2);
    Config::set('fresh.deep.key', true);
    eq(2, Config::get('app.nested.b'));
    eq(true, Config::get('fresh.deep.key'));
    Config::defaults(['app' => ['name' => 'IGNORED', 'extra' => 'yes'], 'only_default' => 1]);
    eq('X', Config::get('app.name'), 'existing values win over defaults');
    eq('yes', Config::get('app.extra'));
    eq(1, Config::get('only_default'));
});

test('Application: path helpers, overrides and the framework default config', function () {
    $app = boot_app(['src/config/app.php' => "<?php return ['name' => 'Custom'];"], ['paths' => ['config' => 'src/config', 'views' => 'src/resources/views']], boot: false);
    ok(str_ends_with($app->configPath(), 'src/config'));
    ok(str_ends_with($app->viewsPath('x/y.php'), 'src/resources/views' . DIRECTORY_SEPARATOR . 'x' . DIRECTORY_SEPARATOR . 'y.php'));
    ok(str_ends_with($app->storagePath(), 'storage'));
    ok(is_dir($app->frameworkPath('Views')), 'framework Views folder');
    eq('Custom', Config::get('app.name'), 'app config overrides defaults');
    eq('.cast.php', Config::get('view.ext'));
    eq(true, Application::instance() === $app);
});

test('Application: container bind / singleton / set / make', function () {
    $app = boot_app(boot: false);
    $n = 0;
    $app->bind('fresh', function () use (&$n) {
        return ++$n;
    });
    $app->singleton('shared', fn() => new stdClass());
    $app->set('thing', 'value');
    eq(1, $app->make('fresh'));
    eq(2, $app->make('fresh'), 'bind creates each time');
    ok($app->make('shared') === $app->make('shared'), 'singleton is shared');
    eq('value', $app->make('thing'));
    ok($app->has('thing') && !$app->has('nope'));
    throws(RuntimeException::class, fn() => $app->make('nope'), 'Nothing is bound');
});

test('Application: providers register then boot, in order; core providers always load', function () {
    $log = new ArrayObject();
    $GLOBALS['provider_log'] = $log;
    $dir = app_dir(['config/app.php' => "<?php return ['providers' => [ProbeProvider::class]];"]);
    eval('class ProbeProvider extends Cast\App\ServiceProvider {
        public function register(): void { $GLOBALS["provider_log"][] = "register"; $this->app->set("probe", true); }
        public function boot(): void { $GLOBALS["provider_log"][] = "boot:" . ($this->app->has("view") ? "view-ready" : "no-view"); }
    }');
    $app = new Application($dir);
    $app->boot();
    eq(['register', 'boot:view-ready'], $log->getArrayCopy());
    ok($app->has('guard') && $app->has('maintenance') && $app->has('view'), 'core providers bound');
    $app->boot();
    eq(2, count($log), 'boot() runs once');
});

test('Application: loads the custom helpers folder', function () {
    $dir = app_dir([
        'config/app.php' => "<?php return [];",
        'config/helpers.php' => "<?php return ['custom' => 'helpers/custom'];",
        'helpers/custom/tax.php' => "<?php if (!function_exists('cast_test_tax')) { function cast_test_tax(float \$x): float { return \$x * 1.1; } }",
    ]);
    (new Application($dir))->boot();
    ok(function_exists('cast_test_tax'));
    eq(11.0, round(cast_test_tax(10.0), 2));
});

test('Config: an app config file only lists what it changes (merged over the framework defaults)', function () {
    boot_app(['config/app.php' => "<?php return ['base_path' => '/x', 'providers' => ['Foo']];"], boot: false);
    eq('/x', Config::get('app.base_path'));
    eq('Test', Config::get('app.name'), 'default from .env survives');
    eq(true, Config::get('app.error_handler'), 'framework default survives');
    eq(['Foo'], Config::get('app.providers'), 'lists are replaced, not appended');
    ok(in_array(\Cast\Http\Middleware\Maintenance::class, Config::get('app.middleware')), 'default middleware survives');
    eq('.cast.php', Config::get('view.ext'));
});

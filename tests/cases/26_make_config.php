<?php

declare(strict_types=1);

use Cast\Core\Config;
use Cast\Support\Crypt;

test('make:config writes a file for every section, and its values are exactly the framework defaults', function () {
    $app = console_app();
    $defaults = [];
    foreach (['app', 'database', 'session', 'cors', 'cdocs', 'dock', 'hashing', 'modules', 'static', 'view', 'api', 'spa', 'auth', 'models', 'helpers', 'request'] as $section) $defaults[$section] = Config::get($section);

    [$code, $out] = cast(['make:config', '--all'], $app);
    eq(0, $code, $out);
    foreach (array_keys($defaults) + [] as $section) {
        $file = $app->basePath("config/$section.php");
        ok(is_file($file), "config/$section.php");
    }
    foreach ($defaults as $section => $expected) {
        $given = include $app->basePath("config/$section.php");
        eq($expected, $given, "config/$section.php repeats the framework's defaults");
    }
    ok(is_file($app->basePath('config/error-handler.php')) && is_file($app->basePath('config/console.php')));
});

test('make:config: one section, --force, --list and unknown names', function () {
    $app = console_app();
    [$code, $out] = cast(['make:config', 'session'], $app);
    eq(0, $code, $out);
    has('Created config/session.php', $out);
    has("'samesite' => env('COOKIE_SITE', 'Lax')", (string) file_get_contents($app->basePath('config/session.php')));

    file_put_contents($app->basePath('config/session.php'), "<?php return ['name' => 'mine'];");
    [$code, $out] = cast(['make:config', 'session'], $app);
    has('already exists', $out);
    eq("<?php return ['name' => 'mine'];", file_get_contents($app->basePath('config/session.php')));
    cast(['make:config', 'session', '--force'], $app);
    has('COOKIE_LIFE', (string) file_get_contents($app->basePath('config/session.php')));

    [$code, $out] = cast(['make:config', '--list'], $app);
    has('error-handler', $out);
    has('session', $out);
    [$code, $out] = cast(['make:config', 'nope'], $app);
    eq(1, $code);
    has('no config section', $out);
    eq(1, cast(['make:config'], $app)[0]);
});

test('config/error-handler.php is the package\'s options; enabled=false turns it off', function () {
    $app = boot_app(['config/error-handler.php' => "<?php return ['log_errors' => false, 'log_directory' => 'var/log/', 'dev_logs' => true];"]);
    $o = \Cast\Services\ErrorProvider::options($app);
    eq(false, $o['log_errors']);
    eq(true, $o['dev_logs']);
    ok(str_ends_with($o['log_directory'], 'var/log' . DIRECTORY_SEPARATOR));

    $generated = console_app();
    cast(['make:config', 'error-handler'], $generated);
    $given = include $generated->basePath('config/error-handler.php');
    $package = (new ReflectionClass(\Anode\ErrorHandler\ErrorHandler::class))->newInstanceWithoutConstructor();
    eq(true, $given['log_errors']);
    eq(false, $given['display_errors']);
    eq('Error Log', $given['email_logging_subject']);
});

test('APP_KEY: key:generate writes it once, sign/unsign and encrypt/decrypt use it', function () {
    $app = boot_app(['.env' => "APP_ENV=development\nAPP_DEBUG=false\n"]);
    [$code, $out] = cast(['key:generate', '--show'], $app);
    eq(0, $code);
    has('base64:', $out);
    ok(!str_contains((string) file_get_contents($app->basePath('.env')), 'APP_KEY'), '--show writes nothing');

    [$code, $out] = cast(['key:generate'], $app);
    eq(0, $code, $out);
    preg_match('/^APP_KEY=(?:"?)(base64:[A-Za-z0-9+\/=]+)/m', (string) file_get_contents($app->basePath('.env')), $m);
    ok(isset($m[1]), 'APP_KEY is in .env');
    [$code, $out] = cast(['key:generate'], $app);
    eq(1, $code);
    has('already set', $out);
    eq(0, cast(['key:generate', '--force'], $app)[0]);
    ok(!str_contains((string) file_get_contents($app->basePath('.env')), $m[1]), '--force replaced it');

    $key = Crypt::generateKey();
    $signed = Crypt::sign('user:42', $key);
    eq('user:42', Crypt::unsign($signed, $key));
    eq(null, Crypt::unsign($signed . 'x', $key));
    eq(null, Crypt::unsign(str_replace('user:42', 'user:43', $signed), $key));
    eq(null, Crypt::unsign($signed, Crypt::generateKey()), 'another key');
    $secret = Crypt::encrypt('card 4242', $key);
    lacks('4242', $secret);
    eq('card 4242', Crypt::decrypt($secret, $key));
    eq(null, Crypt::decrypt($secret, Crypt::generateKey()));
    eq(null, Crypt::decrypt('v1.' . substr($secret, 3, -2) . 'AA', $key), 'tampered');
    eq(null, Crypt::decrypt('garbage', $key));
    throws(RuntimeException::class, fn() => Crypt::key(''), 'APP_KEY is not set');
});

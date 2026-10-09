<?php

declare(strict_types=1);

use Cast\Core\Config;
use Cast\Support\Hash;

test('Hash: argon2id when this PHP has it (else bcrypt), old bcrypt hashes verify and are flagged for rehash', function () {
    $hash = Hash::make('s3cret');
    ok(Hash::check('s3cret', $hash) && !Hash::check('nope', $hash), 'round trip');
    ok(!Hash::check('', ''), 'an empty hash never matches');
    $expected = in_array('argon2id', password_algos(), true) ? 'argon2id' : 'bcrypt';
    eq($expected, Hash::driver());
    ok($hash !== Hash::make('s3cret'), 'salted');

    $old = password_hash('s3cret', PASSWORD_BCRYPT, ['cost' => 10]);
    ok(Hash::check('s3cret', $old), 'a bcrypt hash from an older app still verifies');
    ok(str_replace('$2y$', '$2a$', $old) !== $old && Hash::check('s3cret', str_replace('$2y$', '$2a$', $old)), 'and the $2a$ form');
    Config::set('hashing.driver', 'bcrypt');
    Config::set('hashing.bcrypt.cost', 12);
    eq('bcrypt', Hash::driver());
    ok(Hash::needsRehash($old), 'cost 10 is below the setting');
    ok(!Hash::needsRehash(Hash::make('x')), 'a fresh hash is current');
    ok(str_starts_with(hashPassword('x'), '$2y$12$') && verifyPassword('x', hashPassword('x')), 'the helpers use it');
});

test('encrypt()/decrypt() use the app key: another key cannot read it, a changed value is refused', function () {
    $key = \Cast\Support\Crypt::generateKey();
    $secret = \Cast\Support\Crypt::encrypt('card 4242', $key);
    eq('card 4242', \Cast\Support\Crypt::decrypt($secret, $key));
    eq(null, \Cast\Support\Crypt::decrypt($secret, \Cast\Support\Crypt::generateKey()));
    eq(null, \Cast\Support\Crypt::decrypt(substr($secret, 0, -2) . 'AA', $key));
});

function cmd_run(string $class, array $args, ?string $stdin = null, ?\Cast\App\Application $app = null): array
{
    $app ??= boot_app(['.env' => "APP_NAME=Shop\nAPP_ENV=development\nAPP_DEBUG=true\nAPP_KEY=\nCOOKIE_SECURE=false\n# DB_HOST=127.0.0.1\nDB_CONN=sqlite\nDB_NAME=storage/database.sqlite\n"]);
    $screen = fopen('php://memory', 'w+');
    $cmd = new $class($app);
    if ($stdin !== null && property_exists($cmd, 'stdin')) $cmd->stdin = $stdin;
    $code = $cmd->handle(new \Cast\Console\Input([$cmd->name(), ...$args]), new \Cast\Console\Output($screen, false));
    rewind($screen);
    return [$code, (string) stream_get_contents($screen), $app];
}

test('requirements: PHP version, extensions and limits are reported; --json is valid; production adds the live settings', function () {
    [$code, $out] = cmd_run(\Cast\Console\Commands\RequirementsCommand::class, []);
    has('PHP 8.1.0 or newer', $out);
    has('extension openssl', $out);
    [, $json] = cmd_run(\Cast\Console\Commands\RequirementsCommand::class, ['--json', '--production']);
    $rows = json_decode($json, true);
    ok(is_array($rows) && isset($rows[0]['status'], $rows[0]['check']), 'json rows');
    ok(count(array_filter($rows, fn($r) => str_contains($r['check'], 'display_errors'))) === 1, 'production checks are added');
    eq(8 * 1048576, \Cast\Support\Requirements::bytes('8M'));
    eq(-1, \Cast\Support\Requirements::bytes('-1'));
    eq(1073741824, \Cast\Support\Requirements::bytes('1G'));
    eq(1, \Cast\Support\Requirements::failures([['fail', 'x', ''], ['ok', 'y', '']]));
});

test('deploy:init writes production settings from the answers, keeps the rest of .env, and is careful', function () {
    $app = boot_app(['.env' => "APP_NAME=\"My Shop\"\nAPP_ENV=development\nAPP_DEBUG=true\nAPP_KEY=base64:devkey\nCAST_DOCK=true\nCOOKIE_DOMAIN=\nCOOKIE_SECURE=false\nDB_CONN=sqlite\nDB_NAME=storage/database.sqlite\n# DB_HOST=127.0.0.1\n# DB_USER=\n# DB_PASS=\nMY_OWN=keep\n"]);
    $class = \Cast\Console\Commands\DeployInitCommand::class;
    [$code, $out] = cmd_run($class, ['--url=https://app.example.com', '--cookie-domain=.example.com', '--db-conn=mysql', '--db-host=db.internal', '--db-name=shop', '--db-user=shop', '--db-pass=p@ss word#1', '--cors=https://app.example.com', '-n'], null, $app);
    eq(0, $code, $out);
    $env = (string) file_get_contents($app->basePath('.env.production'));
    has("APP_ENV=production", $env);
    has("APP_DEBUG=false", $env);
    has("CAST_DOCK=false", $env);
    has("COOKIE_SECURE=true", $env);
    has("COOKIE_DOMAIN=.example.com", $env);
    has("COOKIE_SITE=Lax", $env);
    has("DB_CONN=mysql", $env);
    has("DB_HOST=db.internal", $env);
    has('DB_PASS="p@ss word#1"', $env);
    has("CORS_ALLOWED_ORIGINS=https://app.example.com", $env);
    has("MY_OWN=keep", $env);
    has('APP_NAME="My Shop"', $env);
    ok(preg_match('/^APP_KEY=base64:/m', $env) && !str_contains($env, 'devkey'), 'a new key for production');
    eq(substr_count($env, 'DB_HOST='), 1, 'the commented line was replaced, not duplicated');
    if (DIRECTORY_SEPARATOR === '/') eq('0600', substr(sprintf('%o', fileperms($app->basePath('.env.production'))), -4), 'only the owner can read it');

    [$again] = cmd_run($class, ['--url=https://x.test', '--db-conn=sqlite', '-n'], null, $app);
    eq(1, $again, 'it will not overwrite without --force');
    [$bad] = cmd_run($class, ['--url=http://x.test', '--same-site=None', '--db-conn=sqlite', '--force', '-n'], null, $app);
    eq(1, $bad, 'SameSite=None without https is refused');
    [$star] = cmd_run($class, ['--url=https://x.test', '--cors=*', '--db-conn=sqlite', '--force', '-n'], null, $app);
    eq(1, $star, 'a * origin is refused');
    [$kept] = cmd_run($class, ['--url=https://x.test', '--db-conn=sqlite', '--keep-key', '--force', '-n'], null, $app);
    eq(0, $kept);
    has('APP_KEY=base64:devkey', (string) file_get_contents($app->basePath('.env.production')));
});

test('deploy:check fails on a development setup and passes a production one', function () {
    $class = \Cast\Console\Commands\DeployCheckCommand::class;
    [$code, $out] = cmd_run($class, ['--skip-db']);
    eq(1, $code);
    has('FAIL  APP_ENV is production', $out);
    has('FAIL  APP_DEBUG is false', $out);
    has('FAIL  APP_KEY is set', $out);
    has('FAIL  the session cookie is https-only', $out);

    $prod = boot_app(['.env' => "APP_NAME=Shop\nAPP_ENV=production\nAPP_DEBUG=false\nAPP_KEY=" . \Cast\Support\Crypt::generateKey() . "\nCOOKIE_SECURE=true\nCOOKIE_SITE=Lax\nDB_CONN=sqlite\nDB_NAME=:memory:\n"]);
    mkdir($prod->basePath('storage'), 0777, true);
    [$code2, $out2] = cmd_run($class, [], null, $prod);
    lacks('FAIL  APP_ENV', $out2);
    lacks('FAIL  APP_KEY', $out2);
    lacks('FAIL  the session cookie', $out2);
    has('the database answers', $out2);
});

test('make:service writes a documented class; the barcode example draws a valid Code 128 symbol', function () {
    $app = boot_app(['.env' => "APP_NAME=Shop\n"]);
    $class = \Cast\Console\Commands\MakeServiceCommand::class;
    [$code, $out] = cmd_run($class, ['Mailer', '--example=plain'], null, $app);
    eq(0, $code, $out);
    $file = $app->basePath('app/Services/MailerService.php');
    ok(is_file($file), 'file');
    has('namespace App\Services;', (string) file_get_contents($file));
    has("app('mailer')", (string) file_get_contents($file), 'explains how to bind and use it');
    has("singleton('mailer'", $out, 'prints the line to add to the provider');
    [$again] = cmd_run($class, ['Mailer', '--example=plain', '-n'], null, $app);
    eq(1, $again, 'no overwrite');
    [$bad] = cmd_run($class, ['X', '--example=nope'], null, $app);
    eq(1, $bad);

    foreach (['printer', 'barcode', 'qrcode'] as $kind) {
        [$c, $o] = cmd_run($class, [ucfirst($kind), "--example=$kind"], null, $app);
        eq(0, $c, $o);
        $path = $app->basePath('app/Services/' . ucfirst($kind) . 'Service.php');
        exec('php -l ' . escapeshellarg($path) . ' 2>&1', $lint, $lc);
        eq(0, $lc, "$kind parses: " . implode(' ', $lint));
    }

    require_once $app->basePath('app/Services/BarcodeService.php');
    require_once $app->basePath('app/Services/PrinterService.php');
    $bc = new \App\Services\BarcodeService();
    $widths = $bc->widths('Hello 42');
    eq(11 * (1 + 8 + 1) + 13, array_sum($widths), 'start + 8 characters + check are 11 modules each, the stop is 13');
    $table = (new ReflectionClassConstant(\App\Services\BarcodeService::class, 'PATTERNS'))->getValue();
    eq(106, count($table), '106 symbols');
    eq(106, count(array_unique($table)), 'all different');
    foreach ($table as $pattern) eq(11, array_sum(array_map('intval', str_split($pattern))), "$pattern is 11 modules");
    $svg = $bc->svg('INV-1');
    has('<svg', $svg);
    has('aria-label="Barcode INV-1"', $svg);
    $threw = false;
    try { $bc->widths("caf\u{e9}"); } catch (InvalidArgumentException) { $threw = true; }
    ok($threw, 'non-ASCII text is refused');

    $printer = new \App\Services\PrinterService('127.0.0.1:1');
    $bytes = $printer->text('TOTAL', bold: true)->cut()->bytes();
    ok(str_starts_with($bytes, "\x1b@") && str_contains($bytes, "TOTAL\n") && str_ends_with($bytes, "\x1dV\x00"), 'ESC/POS bytes: init, text, cut');
    $threw = false;
    try { $printer->send(); } catch (RuntimeException) { $threw = true; }
    ok($threw, 'an unreachable printer is an error you can catch');
});

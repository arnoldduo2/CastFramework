<?php

declare(strict_types=1);

use Cast\Console\Commands\StaticCheckCommand;

function static_check(array $args, ?\Cast\App\Application $app = null): array
{
    $app ??= boot_app(['resources/css/app.css' => 'a{}', 'resources/js/app/app.module.js' => 'x', 'public/index.php' => '<?php', 'public/.htaccess' => '']);
    $screen = fopen('php://memory', 'w+');
    $cmd = new StaticCheckCommand($app);
    $code = $cmd->handle(new \Cast\Console\Input(['static:check', ...$args]), new \Cast\Console\Output($screen, false));
    rewind($screen);
    return [$code, (string) stream_get_contents($screen)];
}

/** A tiny web server (php -S) answering with the given router script; returns [process, port]. */
function tiny_server(string $router): array
{
    $dir = app_dir(['router.php' => $router]);
    for ($port = 8300 + random_int(0, 500); ; $port++) {
        $sock = @stream_socket_server("tcp://127.0.0.1:$port");
        if ($sock) {
            fclose($sock);
            break;
        }
    }
    $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", "$dir/router.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $dir);
    for ($i = 0; $i < 50; $i++) {
        if (@fsockopen('127.0.0.1', $port, $e, $s, 0.1)) break;
        usleep(100000);
    }
    return [$proc, $port];
}

test('static:check: in-process, it lists the folders and says each file would be served', function () {
    [$code, $out] = static_check([]);
    eq(0, $code, $out);
    foreach (['Static folders', '/css/app.css: 200 text/css', '/js/app/app.module.js: 200', '/cast/cast.module.js: 200', '/cdocs/: 200', '/cdocs/data.js: 200', 'the documentation data (data.js) is'] as $needle) has($needle, $out);
    has('To test your web server too', $out);
});

test('static:check: a missing core folder or a missing .htaccess is reported', function () {
    $app = boot_app(['public/index.php' => '<?php'], ['paths' => ['resources' => 'nope']]);
    [$code, $out] = static_check([], $app);
    has('note  public/.htaccess exists', $out, 'a missing .htaccess is a note');
    has('--    /styles/', $out, 'optional folders are not failures');
});

test('static:check --url: every CSS and JS the page links to is requested at the real address', function () {
    [$proc, $port] = tiny_server(<<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/') { echo '<html><head><link rel="stylesheet" href="/css/app.css?v=1"><script src="/js/app.js"></script><script src="https://cdn.example.com/x.js"></script></head></html>'; return; }
if ($path === '/css/app.css') { header('Content-Type: text/css'); echo 'a{}'; return; }
if ($path === '/js/app.js') { header('Content-Type: application/javascript'); echo 'var a=1;'; return; }
if ($path === '/cdocs/') { echo '<html>docs</html>'; return; }
if ($path === '/cdocs/data.js') { header('Content-Type: application/javascript'); echo 'window.CAST_DOCS={}'; return; }
http_response_code(404); echo 'missing';
PHP);
    try {
        [$code, $out] = static_check(["--url=http://127.0.0.1:$port/"]);
        has("Your web server (http://127.0.0.1:$port/)", $out);
        has('ok    page: 200', $out);
        has('/css/app.css?v=1: 200 text/css', $out);
        has('/js/app.js: 200 application/javascript', $out);
        lacks('cdn.example.com', $out, 'other sites are not ours');
        has('/cdocs/data.js: 200', $out);
    } finally {
        proc_terminate($proc);
        proc_close($proc);
    }
});

test('static:check --url: it explains a web server that answers a stylesheet with the home page, a 404, and a wrong APP_BASE_PATH', function () {
    [$proc, $port] = tiny_server(<<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/shop/public/') { echo '<html><head><link rel="stylesheet" href="/shop/public/css/app.css"><script src="/shop/public/js/app.js"></script></head></html>'; return; }
if ($path === '/shop/public/js/app.js') { http_response_code(404); echo 'nope'; return; }
echo '<html>the home page again</html>';        // everything else: the rewrite sends it to the home page
PHP);
    try {
        [$code, $out] = static_check(["--url=http://127.0.0.1:$port/shop/public/"]);
        eq(1, $code);
        has('APP_BASE_PATH is "" but the address has the folder "/shop/public": set  APP_BASE_PATH=/shop/public', $out);
        has('/shop/public/css/app.css: 200 text/html', $out);
        has('a text/html', $out);
        has('another page, usually the home page', $out);
        has('/shop/public/js/app.js: 404', $out);
        has('differ', $out);
    } finally {
        proc_terminate($proc);
        proc_close($proc);
    }
    [$bad] = static_check(['--url=http://127.0.0.1:1/']);
    eq(1, $bad, 'a server that is not running is reported');
    [$syntax] = static_check(['--url=localhost']);
    eq(1, $syntax);
});

test('serve --dry: always passes public/index.php as the router script (last argument)', function () {
    $app = boot_app(['public/index.php' => '<?php']);
    $screen = fopen('php://memory', 'w+');
    $code = (new \Cast\Console\Commands\ServeCommand($app))->handle(new \Cast\Console\Input(['serve', '--dry']), new \Cast\Console\Output($screen, false));
    rewind($screen);
    $out = trim((string) stream_get_contents($screen));
    eq(0, $code);
    ok(str_contains($out, ' -S 127.0.0.1:8000 -t '), $out);
    ok(str_ends_with($out, 'index.php') || str_ends_with($out, 'index.php"'), 'router script is the last argument: ' . $out);
});

test('serve: refuses to start when something already listens on the port', function () {
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr((string) stream_socket_get_name($sock, false), ':'), 1);
    $app = boot_app(['public/index.php' => '<?php']);
    $screen = fopen('php://memory', 'w+');
    $code = (new \Cast\Console\Commands\ServeCommand($app))->handle(new \Cast\Console\Input(['serve', "--port=$port"]), new \Cast\Console\Output($screen, false));
    rewind($screen);
    eq(1, $code);
    $out = (string) stream_get_contents($screen);
    ok(str_contains($out, 'already listening'));
    ok(str_contains($out, '--port=' . ($port + 1)), 'suggests the next port: ' . $out);
    fclose($sock);
});

test('Application::detectBasePath: sub-folder with and without /public in the address', function () {
    eq('/cast-app/public', \Cast\App\Application::detectBasePath('/cast-app/public/index.php', '/cast-app/public/login?x=1'));
    eq('/cast-app', \Cast\App\Application::detectBasePath('/cast-app/public/index.php', '/cast-app/login'));
    eq('/cast-app', \Cast\App\Application::detectBasePath('/cast-app/public/index.php', '/cast-app/'));
    eq('', \Cast\App\Application::detectBasePath('/index.php', '/login'));
    eq('', \Cast\App\Application::detectBasePath('/public/index.php', '/other'));
});

test('init writes a root .htaccess that hands every request to public/', function () {
    $dir = sys_get_temp_dir() . '/cast_init_' . bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents("$dir/composer.json", '{"name":"a/b","require":{}}');
    $app = new \Cast\App\Application($dir);
    $out = new \Cast\Console\Output(fopen('php://memory', 'w+'), false);
    (new \Cast\Console\Commands\InitCommand($app))->handle(new \Cast\Console\Input(['init', '--no-migrate', '--yes']), $out);
    ok(is_file("$dir/.htaccess"), 'root .htaccess created');
    ok(str_contains((string) file_get_contents("$dir/.htaccess"), 'public/$1'));
    ok(is_file("$dir/public/.htaccess"));
});

test('static:check --url: the web server\'s own 404 (Apache) is not blamed on PHP\'s router script', function () {
    [$proc, $port] = tiny_server(<<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/') { echo '<html><head></head></html>'; return; }
http_response_code(404); header('Content-Type: text/html; charset=iso-8859-1'); echo '<!DOCTYPE HTML><html><head><title>404 Not Found</title></head><body><h1>Not Found</h1><p>The requested URL was not found on this server.</p><hr><address>Apache/2.4.58 Server at localhost Port 80</address></body></html>';
PHP);
    try {
        [$code, $out] = static_check(["--url=http://127.0.0.1:$port/"]);
        has("the web server's own 404", $out);
        lacks('router script', $out);
    } finally {
        proc_terminate($proc);
        proc_close($proc);
    }
});

test('static:check --url: Apache\'s folder listing is recognised as "nothing reached index.php"', function () {
    [$proc, $port] = tiny_server(<<<'PHP'
<?php
header('Content-Type: text/html;charset=UTF-8');
echo '<html><head><title>Index of /cast-app</title></head><body><h1>Index of /cast-app</h1></body></html>';
PHP);
    try {
        [$code, $out] = static_check(["--url=http://127.0.0.1:$port/cast-app/"]);
        has("web server's folder listing", $out);
        has('.htaccess', $out);
    } finally {
        proc_terminate($proc);
        proc_close($proc);
    }
});

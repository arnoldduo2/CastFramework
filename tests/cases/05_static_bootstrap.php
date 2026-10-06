<?php

declare(strict_types=1);

use Cast\App\Application;
use Cast\Boot\Bootstrap;
use Cast\Core\Config;
use Cast\Http\Request;
use Cast\Services\StaticResourceProvider;

function static_app(array $extra = []): StaticResourceProvider
{
    $app = boot_app($extra + [
        'resources/css/app.css' => 'body{color:red}',
        'resources/js/app.module.js' => 'console.log(1)',
        'resources/css/secret.php' => '<?php echo "no";',
        'public/assets/vendor/lib/lib.js' => 'var lib=1;',
        'public/assets/fonts/f.woff2' => 'FONT',
        'public/assets/images/logo.png' => 'PNG',
        'public/assets/css/styles.css' => 'html{}',
        'secret.env' => 'SECRET=1',
    ], boot: false);
    return new StaticResourceProvider($app);
}

test('Static: serves CSS and JS from resources with the right Content-Type', function () {
    $s = static_app();
    $css = $s->serve(new Request('GET', '/css/app.css'));
    eq(200, $css->statusCode());
    eq('text/css; charset=utf-8', $css->getHeader('Content-Type'));
    ob_start();
    $css->send();
    eq('body{color:red}', ob_get_clean());

    eq('application/javascript; charset=utf-8', $s->serve(new Request('GET', '/js/app.module.js'))->getHeader('Content-Type'));
});

test('Static: fonts, images and vendor files get their own types (prefix hidden for non-keep_prefix)', function () {
    $s = static_app();
    eq('font/woff2', $s->serve(new Request('GET', '/fonts/f.woff2'))->getHeader('Content-Type'));
    eq('image/png', $s->serve(new Request('GET', '/images/logo.png'))->getHeader('Content-Type'));
    eq('application/javascript; charset=utf-8', $s->serve(new Request('GET', '/public/lib/lib.js'))->getHeader('Content-Type'), 'vendor folder hidden behind /public/');
    eq('text/css; charset=utf-8', $s->serve(new Request('GET', '/styles/styles.css'))->getHeader('Content-Type'));
});

test('Static: path traversal is refused (plain, encoded, backslash, null byte)', function () {
    $s = static_app();
    foreach (['/css/../../.env', '/css/%2e%2e/%2e%2e/.env', '/css/..%2f..%2fsecret.env', '/css/..\\..\\secret.env', "/css/app.css%00.php", '/public/../../secret.env', '/images/../../../etc/passwd'] as $uri) {
        eq(null, $s->serve(new Request('GET', $uri)), $uri);
    }
});

test('Static: only known file types are served, never PHP; missing files fall through', function () {
    $s = static_app();
    eq(null, $s->serve(new Request('GET', '/css/secret.php')), '.php is not served');
    eq(null, $s->serve(new Request('GET', '/css/missing.css')));
    eq(null, $s->serve(new Request('GET', '/css/')), 'a directory');
    eq(null, $s->serve(new Request('GET', '/dashboard')), 'not a static prefix');
    eq(null, $s->serve(new Request('POST', '/css/app.css')), 'only GET/HEAD');
});

test('Static: caching headers: ?v= is immutable, otherwise no-cache; If-Modified-Since gives 304; HEAD has no body', function () {
    $s = static_app();
    eq('public, max-age=31536000, immutable', $s->serve(new Request('GET', '/css/app.css?v=3', ['v' => '3']))->getHeader('Cache-Control'));
    eq('no-cache', $s->serve(new Request('GET', '/css/app.css'))->getHeader('Cache-Control'));

    $r = $s->serve(new Request('GET', '/css/app.css', [], '', ['HTTP_IF_MODIFIED_SINCE' => gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT']));
    eq(304, $r->statusCode());
    eq('', $r->body());

    $head = $s->serve(new Request('HEAD', '/css/app.css'));
    eq(200, $head->statusCode());
    ob_start();
    $head->send();
    eq('', ob_get_clean());
});

test('Static: the map comes from config (a custom prefix, nothing for unlisted ones)', function () {
    $dir = app_dir(['config/static.php' => "<?php return ['assets' => ['dir' => 'www', 'keep_prefix' => false]];", 'www/x.css' => 'x{}', 'resources/css/app.css' => 'a{}']);
    $app = new Application($dir);
    Config::set('static', ['assets' => ['dir' => 'www', 'keep_prefix' => false]]);
    $s = new StaticResourceProvider($app);
    eq(200, $s->serve(new Request('GET', '/assets/x.css'))->statusCode());
    eq(null, $s->serve(new Request('GET', '/css/app.css')), 'default prefixes replaced');
});

test('Static: works behind a base path (/erp-app/css/app.css)', function () {
    $app = boot_app(['config/app.php' => "<?php return ['base_path' => '/erp-app'];", 'resources/css/app.css' => 'a{}'], boot: false);
    eq(200, (new StaticResourceProvider($app))->serve(new Request('GET', '/erp-app/css/app.css'))->statusCode());
});

test('Bootstrap: static files and preflight are answered before the app boots, with security headers', function () {
    $app = boot_app(['resources/css/app.css' => 'a{}', 'config/cors.php' => "<?php return ['allowed_origins' => ['https://app.test']];"], boot: false);
    $b = new Bootstrap($app);

    $css = $b->handle(new Request('GET', '/css/app.css'));
    eq('nosniff', $css->getHeader('X-Content-Type-Options'));
    eq('SAMEORIGIN', $css->getHeader('X-Frame-Options'));
    ok($css->getHeader('Referrer-Policy') !== null && $css->getHeader('Permissions-Policy') !== null);
    eq(null, $css->getHeader('Strict-Transport-Security'), 'no HSTS from the framework');

    eq(null, $b->handle(new Request('GET', '/dashboard')), 'normal requests continue');

    $pre = $b->handle(new Request('OPTIONS', '/anything', [], '', ['HTTP_ORIGIN' => 'https://app.test']));
    eq(204, $pre->statusCode());
    eq('https://app.test', $pre->getHeader('Access-Control-Allow-Origin'));
    has('DELETE', (string) $pre->getHeader('Access-Control-Allow-Methods'));
    has('PATCH', (string) $pre->getHeader('Access-Control-Allow-Methods'));
    has('X-CSRF-TOKEN', (string) $pre->getHeader('Access-Control-Allow-Headers'));
});

test('Bootstrap: CORS only for exact configured origins, never a wildcard', function () {
    boot_app(['config/cors.php' => "<?php return ['allowed_origins' => ['https://ok.test', '*']];"], boot: false);
    eq('https://ok.test', Bootstrap::corsHeaders(new Request('GET', '/', [], '', ['HTTP_ORIGIN' => 'https://ok.test']))['Access-Control-Allow-Origin']);
    eq([], Bootstrap::corsHeaders(new Request('GET', '/', [], '', ['HTTP_ORIGIN' => 'https://evil.test'])));
    eq([], Bootstrap::corsHeaders(new Request('GET', '/', [], '', ['HTTP_ORIGIN' => 'https://ok.test.evil.com'])), 'no prefix matching');
    eq([], Bootstrap::corsHeaders(new Request('GET', '/')), 'no Origin header');
    eq('true', Bootstrap::corsHeaders(new Request('GET', '/', [], '', ['HTTP_ORIGIN' => 'https://ok.test']))['Access-Control-Allow-Credentials']);
});

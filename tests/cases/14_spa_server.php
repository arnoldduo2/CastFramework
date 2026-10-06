<?php

declare(strict_types=1);

use Cast\Core\Router;
use Cast\Core\Session;
use Cast\Core\View;
use Cast\Http\Request;
use Cast\Http\Response;

/** An app with a layout (head with assets, header, content partial, footer) and a few pages. */
function spa_app(array $configs = []): void
{
    $layout = <<<'PHP'
<?php $parent = $data['parentName'] ?? ''; $page = $data['pageName'] ?? ''; ?>
<!DOCTYPE html><html><head><title>Demo | <?= htchars(ucfirst($page)) ?></title>
<?= __modules('app', 'css') ?><?= __modules("$parent.$page", 'css') ?></head>
<body><header>NAV <?= $data['authguard'] ?? '' ?></header>
PHP;
    $footer = <<<'PHP'
<?php $parent = $data['parentName'] ?? ''; $page = $data['pageName'] ?? ''; ?>
<footer>FOOT</footer><?= __modules('app.app', 'js') ?><?= __modules("$parent.$page", 'js') ?></body></html>
PHP;
    $shell = "<?php __includes('layouts.header', \$data); __includes('docs.partials.' . \$data['pageName'], \$data); __includes('layouts.footer', \$data);";
    $files = [
        'resources/views/layouts/header.cast.php' => $layout,
        'resources/views/layouts/footer.cast.php' => $footer,
        'resources/views/docs/docs.cast.php' => $shell,
        'resources/views/docs/partials/docs.cast.php' => '<section>CONTENT <?= htchars($name ?? "") ?></section>',
        'resources/views/docs/modals/edit.cast.php' => '<form>EDIT <?= htchars($name ?? "") ?></form>',
        'resources/views/plain/plain.cast.php' => "<?php __includes('layouts.header', \$data); __includes('plain.partials.plain', \$data); __includes('layouts.footer', \$data);",
        'resources/views/plain/partials/plain.cast.php' => '<section>PLAIN</section>',
        'resources/css/app.css' => 'a{}',
        'resources/js/app/app.module.js' => '//app',
        'resources/css/docs/docs.css' => 'b{}',
        'resources/js/docs/docs.module.js' => '//docs',
    ];
    foreach ($configs as $name => $values) $files["config/$name.php"] = '<?php return ' . var_export($values, true) . ';';
    boot_app($files);

    Router::get('/docs', fn() => app('view')->respond('docs.docs', ['parentName' => 'docs', 'pageName' => 'docs', 'authguard' => 'private', 'spa' => true, 'name' => 'Ann']));
    Router::get('/plain', fn() => app('view')->respond('plain.plain', ['parentName' => 'plain', 'pageName' => 'plain', 'authguard' => 'public']));
    Router::get('/legacy', fn() => views('docs.docs', ['parentName' => 'docs', 'pageName' => 'docs', 'authguard' => 'private', 'spa' => true]));
    Router::get('/edit', fn() => app('view')->respond('docs.modals.edit', ['name' => 'Bo', 'modalClass' => 'modal-sm', 'form' => 'edit-form']));
    Router::get('/go', fn() => Response::redirect('/docs'));
    Router::get('/boom', function () { throw new RuntimeException('secret detail'); });
}

function cast_req(string $uri, array $headers = []): Request
{
    $server = ['HTTP_X_CAST_REQUEST' => '1'];
    foreach ($headers as $name => $value) $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
    return new Request('GET', $uri, [], '', $server);
}

test('SPA server: a page without spa renders as a normal full page', function () {
    spa_app();
    $r = handle(new Request('GET', '/plain'));
    eq(200, $r->statusCode());
    has('<header>NAV public</header>', $r->body());
    has('<section>PLAIN</section>', $r->body());
    lacks('cast-view', $r->body());
    lacks('data-cast', $r->body());
});

test('SPA server: a spa page loaded by the browser is the shell with a skeleton (lazy)', function () {
    spa_app();
    $body = handle(new Request('GET', '/docs'))->body();
    has('<header>NAV private</header>', $body, 'the layout is rendered');
    has('<div id="cast-view" data-cast-page="docs.docs" data-cast-url="/docs" data-cast-lazy="1" aria-busy="true">', $body);
    has('cast-skeleton', $body);
    lacks('CONTENT', $body, 'the content is fetched by the client');
    has("class='resources' data-cast-page href='/css/docs/docs.css", $body, 'page-level css is marked');
    has("<script type='text/javascript' data-cast-page src='/js/docs/docs.module.js", $body);
    lacks("data-cast-page href='/css/app.css", $body, 'app-level css is not');
});

test('SPA server: spa.initial = inline renders the content in the shell', function () {
    spa_app(['spa' => ['initial' => 'inline']]);
    $body = handle(new Request('GET', '/docs'))->body();
    has('<div id="cast-view" data-cast-page="docs.docs" data-cast-url="/docs"><section>CONTENT Ann</section></div>', $body);
    lacks('data-cast-lazy', $body);
});

test('SPA server: spa can be switched off for the whole app', function () {
    spa_app(['spa' => ['enabled' => false]]);
    $body = handle(new Request('GET', '/docs'))->body();
    lacks('cast-view', $body);
    has('<section>CONTENT Ann</section>', $body);
});

test('SPA server: a Cast request gets the page content as a partial envelope with its assets', function () {
    spa_app();
    $r = handle(cast_req('/docs', ['X-Cast-Guard' => 'private']));
    eq(200, $r->statusCode());
    ok($r->isJson());
    $json = json_decode($r->body(), true);
    eq('success', $json['status']);
    $d = $json['data'];
    eq('partial', $d['type']);
    eq('#cast-view', $d['target']);
    eq('<section>CONTENT Ann</section>', $d['html']);
    eq('Test | Docs', $d['title'], 'default title: app name | page');
    eq('private', $d['guard']);
    eq('docs.docs', $d['page']);
    eq('/docs', $d['url']);
    eq(Session::csrfToken(), $d['csrf']);
    eq(1, count($d['css']));
    has('/css/docs/docs.css', $d['css'][0]);
    has('/js/docs/docs.module.js', $d['js'][0]);
    lacks('app.css', json_encode($d['css']), 'app-level assets are not part of a partial');
    eq(2, count($d['own']), 'the page\'s own css and js');
});

test('SPA server: X-Cast-Type page, or a guard change, swaps the whole body', function () {
    spa_app();
    foreach ([['X-Cast-Type' => 'page'], ['X-Cast-Guard' => 'auth']] as $headers) {
        $d = json_decode(handle(cast_req('/docs', $headers))->body(), true)['data'];
        eq('page', $d['type']);
        eq('body', $d['target']);
        has('<header>NAV private</header>', $d['html']);
        has('<div id="cast-view" data-cast-page="docs.docs" data-cast-url="/docs"><section>CONTENT Ann</section></div>', $d['html'], 'the content container is in the new body');
        has('<footer>FOOT</footer>', $d['html']);
        lacks('<script', $d['html'], 'script tags become URLs');
        lacks('<title>', $d['html']);
        eq('Demo | Docs', $d['title'], 'the title comes from the rendered <title>');
        eq(2, count($d['css']), 'app.css and docs.css');
        eq(2, count($d['js']));
        eq(2, count($d['own']), 'only the page\'s own files, not the layout\'s');
        lacks('app.css', json_encode($d['own']));
        has('/css/app.css', $d['css'][0]);
        has('/js/docs/docs.module.js', $d['js'][1]);
    }
});

test('SPA server: a Cast request for a page that is not spa asks the client to load it normally', function () {
    spa_app();
    $d = json_decode(handle(cast_req('/plain'))->body(), true)['data'];
    eq('reload', $d['type']);
    eq('/plain', $d['url']);
});

test('SPA server: a fragment or modal view is rendered by itself, with modalClass and form', function () {
    spa_app();
    $d = json_decode(handle(cast_req('/edit', ['X-Cast-Type' => 'modal']))->body(), true)['data'];
    eq('modal', $d['type']);
    eq('<form>EDIT Bo</form>', $d['html']);
    eq('modal-sm', $d['modalClass']);
    eq('edit-form', $d['form']);
});

test('SPA server: fragment => true renders just that view into a target, with no spa opt-in', function () {
    spa_app();
    Router::get('/rows', fn() => app('view')->respond('docs.modals.edit', ['name' => 'Cy', 'fragment' => true]));
    $d = json_decode(handle(cast_req('/rows', ['X-Cast-Target' => '#side']))->body(), true)['data'];
    eq('partial', $d['type']);
    eq('#side', $d['target']);
    eq('<form>EDIT Cy</form>', $d['html']);
    // the browser (no Cast header) still gets the plain view
    eq('<form>EDIT Cy</form>', handle(new Request('GET', '/rows'))->body());
});

test('SPA server: the views() helper follows the same rules (legacy echo-style controllers)', function () {
    spa_app();
    $full = handle(new Request('GET', '/legacy'));
    has('data-cast-lazy', $full->body());
    $json = json_decode(handle(cast_req('/legacy'))->body(), true);
    eq('partial', $json['data']['type']);
    has('CONTENT', $json['data']['html']);
});

test('SPA server: a redirect answers a Cast request with an envelope, a browser with a 302', function () {
    spa_app();
    $browser = handle(new Request('GET', '/go'));
    eq(302, $browser->statusCode());
    eq('/docs', $browser->getHeader('Location'));

    $r = handle(cast_req('/go'));
    eq(200, $r->statusCode());
    eq(['status' => 'success', 'msg' => '', 'data' => ['type' => 'redirect', 'url' => '/docs']], json_decode($r->body(), true));
});

test('SPA server: errors for Cast requests are JSON envelopes with the HTTP status', function () {
    spa_app();
    $r = handle(cast_req('/nowhere'));
    eq(404, $r->statusCode());
    $json = json_decode($r->body(), true);
    eq('error', $json['status']);
    eq(['type' => 'error', 'code' => 404], $json['data']);

    $r = handle(cast_req('/boom'));
    eq(500, $r->statusCode());
    $json = json_decode($r->body(), true);
    eq('error', $json['status']);
    eq('secret detail', $json['msg'], 'debug on: the real message');
});

test('SPA server: a 500 hides the exception message when debug is off', function () {
    spa_app(['app' => ['debug' => false]]);
    \Cast\Core\Config::set('app.debug', false);
    $json = json_decode(handle(cast_req('/boom'))->body(), true);
    lacks('secret detail', json_encode($json));
    has('Something went wrong', $json['msg']);
});

test('SPA server: maintenance mode makes a Cast client load the maintenance page normally', function () {
    spa_app();
    app('maintenance')->down('Back soon');
    $r = handle(cast_req('/docs'));
    eq(503, $r->statusCode());
    $json = json_decode($r->body(), true);
    eq('reload', $json['data']['type']);
    eq('Back soon', $json['msg']);
    has('Back soon', handle(new Request('GET', '/docs'))->body(), 'browsers get the page');
});

test('SPA server: a guest following a private page via Cast is redirected to the login page', function () {
    spa_app();
    Router::flush();
    Router::middleware([\Cast\Http\Middleware\Authenticate::class, 'private'], fn() => Router::get('/secret', fn() => 'x'));
    $json = json_decode(handle(cast_req('/secret'))->body(), true);
    eq('redirect', $json['data']['type']);
    eq('/login', $json['data']['url']);
});

test('SPA server: View::extractDocument and assets() work on their own', function () {
    $doc = View::extractDocument('<html><head><title>A &amp; B</title><link rel="stylesheet" href="/x.css"></head><body class="z"><p>hi</p><script src="/y.js"></script><script>var inline=1</script></body></html>');
    eq('A & B', $doc['title']);
    eq(['/x.css'], $doc['css']);
    eq(['/y.js'], $doc['js']);
    eq('<p>hi</p><script>var inline=1</script>', $doc['body']);

    spa_app();
    $assets = app('view')->assets(['parentName' => 'docs', 'pageName' => 'docs']);
    eq(1, count($assets['css']));
    eq(['css' => [], 'js' => []], app('view')->assets(['parentName' => 'nope', 'pageName' => 'nope']));
});

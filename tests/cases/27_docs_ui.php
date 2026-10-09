<?php

declare(strict_types=1);

use Cast\Console\Output;
use Cast\Core\Config;
use Cast\Support\DocsBuilder;

$root = dirname(__DIR__, 2);

test('docs: the generated data.js is up to date with the markdown (run  composer docs  after editing a .md file)', function () use ($root) {
    $builder = new DocsBuilder();
    $data = $builder->build("$root/docs", "$root/README.md", null, \Cast\App\Application::VERSION);
    $current = (string) file_get_contents("$root/src/Resources/docs/data.js");
    ok($current === $builder->render($data), 'src/Resources/docs/data.js is stale: run  composer docs');
    ok(count($data['pages']) >= 25, 'the README and the guides become pages');
});

test('docs: every page has a section, no two pages share a slug, and no link points nowhere', function () use ($root) {
    $data = (new DocsBuilder())->build("$root/docs", "$root/README.md");
    $slugs = array_column($data['pages'], 'slug');
    eq(count($slugs), count(array_unique($slugs)), 'unique slugs');
    foreach ($data['pages'] as $page) {
        ok(in_array($page['section'], $data['sections'], true), $page['slug'] . ': section ' . $page['section']);
        preg_match_all('/\]\(#\/([^)\/]+)(?:\/[^)]*)?\)/', $page['md'], $m);
        foreach ($m[1] as $target) ok(in_array($target, $slugs, true), $page['slug'] . " links to #/$target, which is not a page");
    }
});

test('docs builder: front matter, README split, link rewriting, anchors', function () {
    $dir = app_dir([
        'docs/guide.md' => "---\ntitle: The guide\nsection: Start\norder: 2\ndescription: How.\n---\n\n# The guide\n\nSee [the other](other.md#deep-part), [readme](../README.md#sub-part) and [here](#local).\n\n## Local\n",
        'docs/other.md' => "# Other page\n\n## Deep part\ntext\n",
        'docs/secret.md' => "---\ndraft: true\n---\nhidden",
        'docs/_meta.json' => json_encode(['title' => 'T', 'sections' => ['Start', 'More'], 'readme' => ['Big thing' => ['More', 1], 'Contents' => ['_skip', 0]]]),
        'README.md' => "# Proj\n\nIntro text.\n\n## Contents\nskip me\n\n## Big thing\nBody\n\n### Sub part\nmore\n",
    ]);
    $data = (new DocsBuilder())->build("$dir/docs", "$dir/README.md");
    eq('T', $data['title']);
    $bySlug = array_column($data['pages'], null, 'slug');
    eq(['overview', 'guide', 'big-thing', 'other'], array_keys($bySlug), 'overview first, hidden and skipped pages left out, listed sections in order, unlisted ones last');
    eq('The guide', $bySlug['guide']['title']);
    eq('Start', $bySlug['guide']['section']);
    eq('How.', $bySlug['guide']['description']);
    lacks('# The guide', $bySlug['guide']['md'], 'the title is shown by the viewer');
    has('](#/other/deep-part)', $bySlug['guide']['md']);
    has('](#/big-thing/sub-part)', $bySlug['guide']['md'], 'an anchor in the README finds its page');
    has('](#/guide/local)', $bySlug['guide']['md'] . '](#/guide/local)', 'sanity');
    eq('More', $bySlug['big-thing']['section']);
    eq('Other page', $bySlug['other']['title'], 'the first heading is the title');
    eq('big-thing', DocsBuilder::slug('Big thing'));
    eq('legacy-databases-migratesync', DocsBuilder::slug('Legacy databases: `migrate:sync`'));
    eq('spa-pages-without-full-reloads', DocsBuilder::slug('SPA: pages without full reloads'));
});

test('docs: the viewer makes the same heading anchors as the builder (slug() in PHP and in index.html)', function () use ($root) {
    $node = trim((string) shell_exec('command -v node'));
    if ($node === '') return ok(true, 'node is not installed: skipped');
    $html = (string) file_get_contents("$root/src/Resources/docs/index.html");
    preg_match('/function slug\(t\) \{.*?\}\n/s', $html, $m);
    ok(isset($m[0]), 'found slug() in the viewer');
    $headings = ['Legacy databases: `migrate:sync`', 'SPA: pages without full reloads', 'Running on Windows / XAMPP, or in a sub-folder', 'Using another ORM', '1. Install and run', 'make:config <name>'];
    $script = $m[0] . 'console.log(JSON.stringify(' . json_encode($headings) . '.map(slug)));';
    $out = shell_exec(escapeshellarg($node) . ' -e ' . escapeshellarg($script));
    eq(array_map([DocsBuilder::class, 'slug'], $headings), json_decode((string) $out, true));
});

test('/docs: served in development (a redirect to /docs/, the viewer, data.js), refused in production unless docs.enabled', function () {
    $app = boot_app(['.env' => "APP_ENV=development\nAPP_DEBUG=true\n"]);
    $static = new \Cast\Services\StaticResourceProvider($app);
    $get = fn(string $uri) => $static->serve(new \Cast\Http\Request('GET', $uri));

    $r = $get('/docs');
    eq(302, $r->statusCode());
    eq('/docs/', $r->getHeader('Location'));
    $r = $get('/docs/');
    ok($r !== null && $r->statusCode() === 200, '/docs/ is the viewer');
    has('text/html', (string) $r->getHeader('Content-Type'));
    $js = $get('/docs/data.js');
    ok($js !== null && $js->statusCode() === 200 && str_contains((string) $js->getHeader('Content-Type'), 'javascript'), 'data.js is served');
    ok($get('/docs/../../composer.json') === null, 'no way out of the folder');
    ok($get('/docs/nope.txt') === null);

    Config::set('app.env', 'production');
    ok($get('/docs/') === null, 'off in production');
    Config::set('docs.enabled', true);
    ok($get('/docs/') !== null, 'unless docs.enabled');
});

test('docs:build: builds a site from any markdown folder, and --check says when it is stale', function () {
    $dir = app_dir(['docs/a.md' => "---\ntitle: A\nsection: S\n---\ntext\n", 'public/.keep' => '']);
    $app = new \Cast\App\Application($dir);
    $app->boot();
    [$code, $out] = cast(['docs:build', '--source=docs', '--out=public/docs'], $app);
    eq(0, $code, $out);
    ok(is_file("$dir/public/docs/data.js") && is_file("$dir/public/docs/index.html"), 'data.js and the viewer');
    has('Built 1 pages', $out);
    eq(0, cast(['docs:build', '--source=docs', '--out=public/docs', '--check'], $app)[0]);
    file_put_contents("$dir/docs/a.md", "---\ntitle: A\nsection: S\n---\nchanged\n");
    [$code, $out] = cast(['docs:build', '--source=docs', '--out=public/docs', '--check'], $app);
    eq(1, $code);
    has('out of date', $out);
    eq(1, cast(['docs:build', '--source=nope'], $app)[0]);
});

test('the welcome UI: the minimal app and the demo share one layout, stylesheet and home page (starter == stubs)', function () use ($root) {
    $same = [
        'resources/css/app.css' => 'resources/css/app.css.stub',
        'resources/views/layouts/header.cast.php' => 'resources/views/layouts/header.cast.php.stub',
        'resources/views/layouts/footer.cast.php' => 'resources/views/layouts/footer.cast.php.stub',
        'resources/views/home/home.cast.php' => 'resources/views/home/home.cast.php.stub',
        'resources/views/home/partials/home.cast.php' => 'resources/views/home/partials/home.cast.php.stub',
        'resources/views/demo/demo.cast.php' => 'resources/views/demo/demo.cast.php.stub',
        'resources/views/demo/partials/demo.cast.php' => 'resources/views/demo/partials/demo.cast.php.stub',
        'app/Controllers/HomeController.php' => 'app/Controllers/HomeController.php.stub',
        'cast' => 'cast.stub',
        'public/index.php' => 'public/index.php.stub',
        'bootstrap/app.php' => 'bootstrap/app.php.stub',
        'public/.htaccess' => 'public/.htaccess.stub',
    ];
    foreach ($same as $starter => $stub) {
        eq(file_get_contents("$root/src/Stubs/init/$stub"), file_get_contents("$root/starter/$starter"), "starter/$starter differs from its stub (copy one over the other)");
    }
});

test('the footer and body follow the layout rules: fixed footer, 100dvh body', function () use ($root) {
    $css = (string) file_get_contents("$root/src/Stubs/init/resources/css/app.css.stub");
    has('height: 100dvh', $css);
    preg_match('/\.foot \{([^}]*)\}/', $css, $m);
    foreach (['position: fixed', 'bottom: 0', 'width: 100%', 'border-top: 1px solid var(--border-color)', 'text-align: center', 'color: var(--text-off-light)', 'font-size: 12px', 'padding: 5px'] as $rule) has($rule, $m[1] ?? '', $rule);
});

test('every PHP file the demo and init write explains itself (a comment at the top or on the class)', function () use ($root) {
    $files = array_merge(
        glob("$root/starter/app/*/*.php"), glob("$root/starter/app/*/*/*.php"), glob("$root/starter/routes/*.php"), glob("$root/starter/config/*.php"),
        glob("$root/starter/database/*/*.php"), glob("$root/starter/bootstrap/*.php"), glob("$root/starter/public/*.php"), ["$root/starter/cast"],
        glob("$root/src/Stubs/init/app/*/*.php.stub"), glob("$root/src/Stubs/init/routes/*.stub"), glob("$root/src/Stubs/init/config/*.stub"),
    );
    ok(count($files) > 25, 'found the files');
    foreach ($files as $file) {
        $code = (string) file_get_contents($file);
        $comments = preg_match_all('~/\*.*?\*/|(?<![:\'"])//[^\n]*~s', $code, $m) ? strlen(implode('', $m[0])) : 0;
        ok($comments >= 60, str_replace($root . '/', '', $file) . ' has almost no comments (' . $comments . ' characters)');
    }
});

test('the console is colourful on a terminal and plain in a pipe', function () {
    $stream = fopen('php://memory', 'w+');
    $plain = new Output($stream);
    eq(false, $plain->colors(), 'a stream is not a terminal');
    eq('serve', $plain->color('serve', 'green'));
    $color = new Output($stream, true);
    has("\033[", $color->color('serve', 'green'));
    $color->table(['Name', 'What'], [['serve', 'start it']], 'green');
    rewind($stream);
    $text = (string) stream_get_contents($stream);
    has("\033[", $text);
    has('serve', $text);
    has('start it', $text);
    $plainTable = fopen('php://memory', 'w+');
    (new Output($plainTable, false))->table(['Name', 'What'], [['serve', 'start it']], 'green');
    rewind($plainTable);
    lacks("\033", (string) stream_get_contents($plainTable));
});

test('make:command writes a command that already explains itself and is documented for help', function () {
    $app = console_app();
    [$code, $out] = cast(['make:command', 'SyncStock'], $app);
    eq(0, $code, $out);
    $file = $app->basePath('app/Console/Commands/SyncStockCommand.php');
    $code = (string) file_get_contents($file);
    foreach (['protected array $arguments', 'protected array $options', 'protected array $examples', "protected string \$name = 'sync:stock'", 'config/console.php', '->table('] as $needle) has($needle, $code, $needle);
});

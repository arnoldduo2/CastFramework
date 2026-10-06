<?php

declare(strict_types=1);

namespace Cast\Core;

use Cast\App\Application;
use Cast\Contracts\ViewRenderer;
use Cast\Http\Request;
use Cast\Http\Response;
use CastTemplateEngine\CastTemplate;
use Throwable;

/**
 * The view layer, built on CastTemplateEngine (`.cast.php` files may use component tags; plain PHP works too).
 * Views are looked up in the app's views folder first, then in the framework's own `Views/` (error pages).
 */
final class View implements ViewRenderer
{
    private CastTemplate $engine;
    private CastTemplate $fallback;
    private string $ext;
    private string $viewsDir;
    private string $frameworkViews;
    /** @var array<string, mixed> */
    private array $shared = [];
    /** The page being rendered by {@see respond()}: key ("parent.page[.tab]"), content partial name, and mode. */
    private ?array $page = null;

    public function __construct(private Application $app)
    {
        $this->ext = (string) Config::get('view.ext', '.cast.php');
        $this->viewsDir = $app->viewsPath();
        $this->frameworkViews = $app->frameworkPath('Views');
        $components = $this->viewsDir . DIRECTORY_SEPARATOR . trim((string) Config::get('view.components', 'components'), '/\\');
        $cache = $app->storagePath('framework/views');
        $fresh = (bool) Config::get('app.debug', false) || Config::get('app.env') === 'development';

        $this->engine = new CastTemplate($components, $this->ext, ['viewsDir' => $this->viewsDir, 'cacheDir' => $cache, 'checkModified' => $fresh]);
        $this->fallback = new CastTemplate($this->frameworkViews, $this->ext, ['viewsDir' => $this->frameworkViews, 'cacheDir' => $cache, 'checkModified' => $fresh]);
    }

    /** Data every view receives (explicit data wins). */
    public function share(string|array $key, mixed $value = null): void
    {
        $this->shared = is_array($key) ? [...$this->shared, ...$key] : [...$this->shared, $key => $value];
    }

    public function exists(string $view): bool
    {
        $path = str_replace('.', DIRECTORY_SEPARATOR, $view) . $this->ext;
        return is_file($this->viewsDir . DIRECTORY_SEPARATOR . $path) || is_file($this->frameworkViews . DIRECTORY_SEPARATOR . $path);
    }

    /** Render a view to a string. The data is available as variables, and as `$data` (the whole array). */
    public function render(string $view, array $data = []): string
    {
        $vars = [...$this->shared, ...$data];
        $vars['data'] ??= $vars;
        $path = str_replace('.', DIRECTORY_SEPARATOR, $view) . $this->ext;
        $engine = is_file($this->viewsDir . DIRECTORY_SEPARATOR . $path) ? $this->engine : $this->fallback;
        return $engine->render($view, $vars);
    }

    /**
     * Render a view as an HTTP response. This is the one place that decides full page, partial or modal:
     *
     *  - a normal request, or a page that is not `'spa' => true`: the full HTML page;
     *  - a `'spa' => true` page requested by the browser: the same page, with its content area (the page partial)
     *    left as a skeleton for the client to fetch (`spa.initial` = 'lazy', default) or filled in ('inline');
     *  - a Cast request (`X-Cast-Request: 1`): a JSON envelope `{status, msg, data:{type, target, title, html, css, js, own,
     *    guard, url, page, modalClass, form, csrf}}` the client swaps into the page.
     *
     * The data keys it reads: `parentName`, `pageName`, `tabName`, `authguard`, `spa`, `title`, `partial`, `fragment`,
     * `type`, `target`, `modalClass`, `form`.
     */
    public function respond(string $view, array $data = [], int $status = 200): Response
    {
        $request = Request::current();
        $this->page = $this->describe($data);

        try {
            if ($request->isCast()) return $this->castResponse($view, $data, $status, $request);

            $this->page['mode'] = $this->page['spa'] ? (Config::get('spa.initial', 'lazy') === 'inline' ? 'inline' : 'lazy') : 'plain';
            return Response::html($this->render($view, $data), $status);
        } finally {
            $this->page = null;
        }
    }

    /** @return array{key: string, partial: string, spa: bool, mode: string} */
    private function describe(array $data): array
    {
        $parent = (string) ($data['parentName'] ?? '');
        $name = (string) ($data['pageName'] ?? '');
        $tab = (string) ($data['tabName'] ?? '');
        $key = implode('.', array_filter([$parent, $name, $tab], fn($part) => $part !== ''));

        return [
            'key' => $key,
            'partial' => (string) ($data['partial'] ?? ($parent !== '' && $name !== '' ? "$parent.partials.$name" : '')),
            'spa' => ($data['spa'] ?? false) === true && Config::get('spa.enabled', true) !== false,
            'mode' => 'plain',
        ];
    }

    private function castResponse(string $view, array $data, int $status, Request $request): Response
    {
        $page = $this->page;
        $guard = isset($data['authguard']) ? (string) $data['authguard'] : null;
        $payload = [
            'type' => 'partial',
            'target' => $data['target'] ?? $request->castTarget() ?? (string) Config::get('spa.view', '#cast-view'),
            'title' => $data['title'] ?? null,
            'html' => '',
            'css' => [],
            'js' => [],
            'own' => [],
            'guard' => $guard,
            'url' => $request->uri(),
            'page' => $page['key'],
            'modalClass' => $data['modalClass'] ?? null,
            'form' => $data['form'] ?? null,
            'csrf' => Session::csrfToken(),
        ];
        $type = (string) ($data['type'] ?? $request->castType());

        // a fragment (a modal, or a view that is already just a piece of page): render the view itself
        if ($type === 'modal' || ($data['fragment'] ?? false) === true) {
            $payload['type'] = $type === 'modal' ? 'modal' : 'partial';
            $payload['html'] = $this->render($view, $data);
            return Response::success('', $payload, $status);
        }

        // a whole page: only for pages that opted in, otherwise let the browser load it normally
        if (!$page['spa']) {
            return Response::success('', ['type' => 'reload', 'url' => $request->uri(), 'csrf' => $payload['csrf']], $status);
        }

        $clientGuard = $request->castGuard();
        $guardChanged = $guard !== null && $clientGuard !== null && $guard !== $clientGuard;

        if ($type !== 'page' && !$guardChanged && $page['partial'] !== '' && $this->exists($page['partial'])) {
            $payload['html'] = $this->render($page['partial'], $data);
            $payload['title'] ??= $this->defaultTitle($data);
            ['css' => $payload['css'], 'js' => $payload['js']] = $this->assets($data);
            $payload['own'] = [...$payload['css'], ...$payload['js']];
            return Response::success('', $payload, $status);
        }

        // the whole body (login to private area, a different layout, or no partial to swap): the content
        // container is part of it, filled in, so later navigation can swap just the content
        $this->page['mode'] = 'inline';
        $document = self::extractDocument($this->render($view, $data));
        $payload['type'] = 'page';
        $payload['target'] = (string) Config::get('spa.root', 'body');
        $payload['html'] = $document['body'];
        $payload['title'] ??= $document['title'] ?? $this->defaultTitle($data);
        $payload['css'] = $document['css'];
        $payload['js'] = $document['js'];
        $own = $this->assets($data);   // the page's own files: the client removes them when it leaves the page
        $payload['own'] = [...$own['css'], ...$own['js']];
        return Response::success('', $payload, $status);
    }

    private function defaultTitle(array $data): string
    {
        $name = (string) ($data['pageName'] ?? '');
        $app = (string) Config::get('app.name', 'App');
        return $name === '' ? $app : $app . ' | ' . ucwords(str_replace(['-', '_'], ' ', $name));
    }

    /**
     * The page-level CSS and JS URLs for a page (`resources/css/{parent}/{page}.css`, `resources/js/{parent}/{page}.module.js`,
     * and the same with the tab), exactly the files `__modules()` would link, so a partial swap loads what a full page would.
     * @return array{css: list<string>, js: list<string>}
     */
    public function assets(array $data): array
    {
        $parent = (string) ($data['parentName'] ?? '');
        $name = (string) ($data['pageName'] ?? '');
        $tab = (string) ($data['tabName'] ?? '');
        $found = ['css' => [], 'js' => []];
        if ($parent === '' || $name === '') return $found;

        foreach (array_filter(["$parent.$name", $tab !== '' ? "$parent.$name.$tab" : '']) as $module) {
            foreach (['css', 'js'] as $type) {
                if ($url = $this->moduleUrl($module, $type)) $found[$type][] = $url;
            }
        }
        return $found;
    }

    /**
     * Pull the title, the body markup and the stylesheet/script URLs out of a rendered HTML document, so a full
     * page can be swapped into the running page. Script and stylesheet tags are returned as URLs (the client
     * loads each only once); inline scripts are not carried over.
     * @return array{title: ?string, body: string, css: list<string>, js: list<string>}
     */
    public static function extractDocument(string $html): array
    {
        $title = preg_match('~<title[^>]*>(.*?)</title>~is', $html, $m) ? trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5)) : null;

        $linkTag = '~<link\b[^>]*\brel\s*=\s*([\'"])stylesheet\1[^>]*>~i';
        $scriptTag = '~<script\b[^>]*\bsrc\s*=\s*([\'"])(.*?)\1[^>]*>\s*</script>~is';
        $css = $js = [];

        if (preg_match_all($linkTag, $html, $links)) {
            foreach ($links[0] as $tag) {
                if (preg_match('~\bhref\s*=\s*([\'"])(.*?)\1~i', $tag, $h)) $css[] = html_entity_decode($h[2], ENT_QUOTES | ENT_HTML5);
            }
        }
        if (preg_match_all($scriptTag, $html, $scripts)) {
            foreach ($scripts[2] as $src) $js[] = html_entity_decode($src, ENT_QUOTES | ENT_HTML5);
        }

        $body = preg_match('~<body\b[^>]*>(.*)</body>~is', $html, $m) ? $m[1] : $html;
        $body = (string) preg_replace([$linkTag, $scriptTag], '', $body);

        return ['title' => $title, 'body' => trim($body), 'css' => array_values(array_unique($css)), 'js' => array_values(array_unique($js))];
    }

    /**
     * Include a partial. While a SPA page is being rendered, the page's own content partial is wrapped in the
     * container the client swaps into (`#cast-view`): a skeleton in 'lazy' mode, the real content in 'inline' mode.
     */
    public function partial(string $name, array $data = []): string
    {
        $page = $this->page;
        if ($page !== null && $page['spa'] && $page['mode'] !== 'plain' && $name === $page['partial']) {
            $open = '<div id="' . ltrim((string) Config::get('spa.view', '#cast-view'), '#') . '" data-cast-page="' . htmlspecialchars($page['key'], ENT_QUOTES)
                . '" data-cast-url="' . htmlspecialchars(Request::current()->uri(), ENT_QUOTES) . '"'
                . ($page['mode'] === 'lazy' ? ' data-cast-lazy="1" aria-busy="true"' : '') . '>';
            $inner = $page['mode'] === 'lazy'
                ? $this->render('spa.skeleton', $data) . '<noscript><p>JavaScript is required to load this page.</p></noscript>'
                : $this->render($name, $data);
            echo $out = $open . $inner . '</div>';
            return $out;
        }

        $out = $this->render($name, $data);
        echo $out;
        return $out;
    }

    /**
     * A component: a `.cast.php` file under the components folder (props are camelCased), or a legacy `.php`
     * component that reads `$data`. With `$asVar`, a legacy component's `return` value is used.
     */
    public function component(string $name, array|string $data = [], bool $asVar = false): mixed
    {
        $data = is_array($data) ? $data : [];
        $path = str_replace('.', '/', $name);
        $dir = $this->viewsDir . DIRECTORY_SEPARATOR . trim((string) Config::get('view.components', 'components'), '/\\');

        if (is_file($dir . '/' . $path . $this->ext)) {
            return $this->engine->component($path, $data);
        }

        $file = $dir . '/' . $path . '.php';
        if (!is_file($file)) return '';

        $run = static function (string $__file, array $data) {
            return include $__file;
        };
        if ($asVar) return $run($file, $data);

        $level = ob_get_level();
        ob_start();
        try {
            $run($file, $data);
        } catch (Throwable $e) {
            while (ob_get_level() > $level) ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }

    /** `<link>` / `<script>` for `resources/css/{name}.css` or `resources/js/{name}.module.js`, when the file exists. */
    public function modules(string $name, string $type = 'js'): string
    {
        $href = $this->moduleUrl($name, $type);
        if ($href === null) return '';

        // the page's own files are marked, so the client can remove them when it leaves the page
        $mark = $this->page !== null && trim(str_replace('.', '/', $name), '/') === str_replace('.', '/', $this->page['key']) ? ' data-cast-page' : '';
        return $type === 'css'
            ? "<link rel='stylesheet' class='resources'$mark href='$href'/>"
            : "<script type='text/javascript'$mark src='$href'></script>";
    }

    /** The versioned URL of a module file, or null when the file does not exist. */
    private function moduleUrl(string $name, string $type): ?string
    {
        $name = trim(str_replace('.', '/', $name), '/');
        if ($name === '') return null;

        $url = $type === 'css' ? "/css/$name.css" : "/js/$name.module.js";
        if (!is_file($this->app->resourcesPath(ltrim($url, '/')))) return null;

        return Config::get('app.base_path', '') . $url . app_version();
    }

    /** The HTML of an error page (`errors.{code}`, then `errors.error`; or a named view such as 'maintenance'). */
    public function errorPage(int $code, string $message = '', ?string $view = null, array $data = []): string
    {
        $title = Response::PHRASES[$code] ?? 'Error';
        $candidates = $view !== null ? [$view] : ["errors.$code", 'errors.error'];
        foreach ($candidates as $candidate) {
            if ($this->exists($candidate)) {
                return $this->render($candidate, [
                    ...$data,
                    'code' => $code,
                    'title' => $title,
                    'message' => $message !== '' ? $message : $title,
                    'appName' => (string) Config::get('app.name', 'App'),
                    'homeUrl' => route('/'),
                ]);
            }
        }
        return "<h1>$code $title</h1><p>" . htmlspecialchars($message, ENT_QUOTES) . '</p>';
    }
}

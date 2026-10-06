<?php

declare(strict_types=1);

namespace Cast\Core;

use Cast\App\Application;
use Cast\Contracts\ViewRenderer;
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

    /** Render a view as an HTTP response (a full page now; the SPA envelope in the next milestone). */
    public function respond(string $view, array $data = [], int $status = 200): Response
    {
        return Response::html($this->render($view, $data), $status);
    }

    public function partial(string $name, array $data = []): string
    {
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
        $name = trim(str_replace('.', '/', $name), '/');
        if ($name === '') return '';

        $url = $type === 'css' ? "/css/$name.css" : "/js/$name.module.js";
        if (!is_file($this->app->resourcesPath(ltrim($url, '/')))) return '';

        $href = Config::get('app.base_path', '') . $url . app_version();
        return $type === 'css'
            ? "<link rel='stylesheet' class='resources' href='$href'/>"
            : "<script type='text/javascript' src='$href'></script>";
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

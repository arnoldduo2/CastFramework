<?php

declare(strict_types=1);

use Cast\App\Application;
use Cast\Core\Config;
use Cast\Core\Env;
use Cast\Core\Session;
use Cast\Core\View;
use Cast\Http\HttpException;
use Cast\Http\Response;

if (!function_exists('app')) {
    /** The application, or a service from its container: `app('view')`. */
    function app(?string $id = null): mixed
    {
        $app = Application::instance() ?? throw new RuntimeException('The application has not been created yet.');
        return $id === null ? $app : $app->make($id);
    }
}

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        return Env::get($key, $default);
    }
}

if (!function_exists('config')) {
    /** `config('app.name')`, or `config(['app.name' => 'X'])` to set values. */
    function config(string|array $key, mixed $default = null): mixed
    {
        if (is_array($key)) {
            foreach ($key as $k => $v) Config::set($k, $v);
            return null;
        }
        return Config::get($key, $default);
    }
}

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        return app()->basePath($path);
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        return app()->storagePath($path);
    }
}

if (!function_exists('resource_path')) {
    function resource_path(string $path = ''): string
    {
        return app()->resourcesPath($path);
    }
}

if (!function_exists('public_path')) {
    function public_path(string $path = ''): string
    {
        return app()->publicPath($path);
    }
}

if (!function_exists('dir_scan')) {
    /** `require_once` every file in a directory (the path must end with a slash). */
    function dir_scan(string $dir): bool
    {
        foreach (scandir($dir) ?: [] as $file) {
            if ($file !== '.' && $file !== '..' && is_file("$dir$file")) require_once "$dir$file";
        }
        return true;
    }
}

// ------------------------------------------------------------------ config files

if (!function_exists('useConfig')) {
    /** The array a config file returns: `useConfig('colors')` (config/colors.php). */
    function useConfig(string $fileName): array
    {
        $value = Config::get($fileName);
        return is_array($value) ? $value : [];
    }
}

if (!function_exists('__getConfig')) {
    /** A key of `config/config.json` (or another JSON file in the config folder). Returns [] when it isn't there. */
    function __getConfig(string $name, ?string $file = null): array
    {
        $path = app()->configPath($file ?? 'config.json');
        if (!is_file($path)) return [];
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) && isset($data[$name]) && is_array($data[$name]) ? $data[$name] : [];
    }
}

// ------------------------------------------------------------------------- views

if (!function_exists('views')) {
    /** Render a view and echo it (`'module.page'` or `'module/page'`). Returns true. Use `$this->view()` in controllers to get a Response. */
    function views(string $view, array $data = []): mixed
    {
        // respond() gives the full page, or the JSON envelope for a Cast (SPA) request
        echo app('view')->respond($view, $data)->body();
        return true;
    }
}

if (!function_exists('Component')) {
    /** Render a component: `Component('btns.add-new-btn', ['link' => 'fleet'])`. With `$isVar`, returns what the component file returns. */
    function Component(string $component, string|array $attr_data = [], bool $isVar = false): mixed
    {
        return app('view')->component($component, $attr_data, $isVar);
    }
}

if (!function_exists('__includes')) {
    /** Include a partial or layout (echoes it). With `$withAuth`, only when someone is logged in. */
    function __includes(string $name, array $data = [], bool $withAuth = false): mixed
    {
        if ($withAuth && !__getUser()) return '';
        return app('view')->partial($name, $data);
    }
}

if (!function_exists('__modules')) {
    /** The `<link>` / `<script>` for a module file (resources/css/{name}.css, resources/js/{name}.module.js) when it exists. */
    function __modules(string $name, string $type = 'js', int $levels = 2, bool $withAuth = false): string
    {
        if ($withAuth && !__getUser()) return '';
        return app('view')->modules($name, $type);
    }
}

if (!function_exists('__cast')) {
    /**
     * The SPA client: its stylesheet, and its script with the page's settings as data attributes (no inline data blocks).
     * Put it once in your layout's `<head>`; `$guard` is the page's `authguard`.
     */
    function __cast(string $guard = ''): string
    {
        $base = Config::get('app.base_path', '') . '/cast';
        $v = app_version();
        return "<link rel='stylesheet' href='$base/cast.css$v'/>"
            . "<script src='$base/cast.module.js$v' data-cast-root='" . htmlspecialchars((string) Config::get('spa.root', 'body'), ENT_QUOTES)
            . "' data-cast-view='" . htmlspecialchars((string) Config::get('spa.view', '#cast-view'), ENT_QUOTES)
            . "' data-cast-guard='" . htmlspecialchars($guard, ENT_QUOTES) . "'></script>";
    }
}

if (!function_exists('render404')) {
    /** Echo the not-found page (or another HTTP error page). */
    function render404(string $title = 'Page Not Found', string $message = 'This page could not be found!', int $code = 404): bool
    {
        if (!headers_sent()) http_response_code($code);
        echo app('view')->errorPage($code, $message);
        return true;
    }
}

// ----------------------------------------------------------------------- routing

if (!function_exists('route')) {
    /** A URL for a path under the app: `route('/dashboard', ['tab' => 'sales'])`. Absolute URLs are returned as they are. */
    function route(string $route = '/', array $params = []): string
    {
        $query = $params ? '?' . http_build_query($params) : '';
        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $route)) return $route . $query;
        return Config::get('app.base_path', '') . '/' . ltrim($route, '/') . $query;
    }
}

if (!function_exists('route_to')) {
    /** Redirect to a path. Sends the redirect and stops; in the CLI it returns the Response instead. */
    function route_to(string $route = '/', array $params = []): Response
    {
        $response = Response::redirect(route($route, $params));
        if (PHP_SAPI === 'cli') return $response;
        $response->send();
        exit;
    }
}

if (!function_exists('abort')) {
    function abort(int $code, string $message = ''): never
    {
        throw new HttpException($code, $message);
    }
}

if (!function_exists('_access')) {
    /** Use at the top of POST-only endpoints: a plain GET is turned away. */
    function _access(?string $location = null): mixed
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            throw new HttpException(405, 'This address only accepts form submissions.', ['Allow' => 'POST']);
        }
        return $location ?? Config::get('app.name');
    }
}

// ---------------------------------------------------------------------- versions

if (!function_exists('file_control')) {
    /** The `?v=` suffix for asset URLs: a fresh value on every request while `app.debug` is on. */
    function file_control(int|string $current_version = 1): string
    {
        if (Config::get('app.debug')) $current_version = date('dmhis');
        return "?v=$current_version";
    }
}

if (!function_exists('app_version')) {
    /** Cache-busting suffix from `app.version` (production), or a fresh one in debug. */
    function app_version(bool $asFileControl = true): string
    {
        $version = Config::get('app.version') ?: null;
        if (!$version && $asFileControl) return file_control();
        return $asFileControl ? file_control($version) : (string) $version;
    }
}

// ---------------------------------------------------------------- alerts / state

if (!function_exists('sendAlert')) {
    /**
     * An alert for the front end: `{"type":"error","msg":"...","callback":null}`. With `$useSession` it is also
     * flashed so the next page shows it (see `__getAlerts()`).
     */
    function sendAlert(string $type, string $msg = '', bool $useSession = false, ?array $callback = null, bool $busyLoader = false): string
    {
        $alert = json_encode(['type' => $type, 'msg' => $msg, 'callback' => $callback]);
        if ($useSession) Session::flash('alerts', $alert);
        if ($busyLoader) __busyLoader();
        return $alert;
    }
}

if (!function_exists('__getAlerts')) {
    /** The hidden element the front end reads a flashed alert from (empty when there is none). */
    function __getAlerts(): string
    {
        $alert = Session::getFlash('alerts');
        return $alert ? "<div class='alerts d-none'>" . htmlspecialchars((string) $alert, ENT_NOQUOTES) . '</div>' : '';
    }
}

if (!function_exists('__busyLoader')) {
    /** `__busyLoader()` asks the next page to show the loader; `__busyLoader(false)` reads (and clears) that request. */
    function __busyLoader(bool $set = true, bool $value = true): bool
    {
        if ($set) {
            Session::flash('loader', $value);
            return false;
        }
        return (bool) Session::getFlash('loader', false);
    }
}

if (!function_exists('clearState')) {
    function clearState(string $key): void
    {
        Session::set("state.$key", ['cleared' => true]);
    }
}

if (!function_exists('__getSess')) {
    function __getSess(string $key): array|string|null
    {
        $value = Session::get($key);
        return $value ?: null;
    }
}

// --------------------------------------------------------------------- debugging

if (!function_exists('dd')) {
    function dd(mixed ...$var): never
    {
        echo '<pre style="color:gray">';
        print_r($var);
        echo '</pre>';
        exit(1);
    }
}

if (!function_exists('dump')) {
    function dump(mixed ...$var): void
    {
        echo '<pre style="color:gray">';
        print_r($var);
        echo '</pre>';
    }
}

if (!function_exists('vd')) {
    function vd(mixed ...$var): never
    {
        echo '<pre style="color:gray">';
        var_dump($var);
        echo '</pre>';
        exit(1);
    }
}

if (!function_exists('__prev')) {
    function __prev(mixed ...$var): void
    {
        echo '<pre style="color:gray">';
        print_r($var);
        echo '</pre>';
    }
}

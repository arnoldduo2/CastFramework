<?php

declare(strict_types=1);

namespace Cast\Boot;

use Cast\App\Application;
use Cast\Core\Config;
use Cast\Http\Request;
use Cast\Http\Response;
use Cast\Services\StaticResourceProvider;

/**
 * Work that happens before the app boots: static files, CORS preflight, and the headers every response gets.
 */
final class Bootstrap
{
    public function __construct(private Application $app) {}

    /** Answer static files and CORS preflight requests. Returns null when the request needs the application. */
    public function handle(Request $request): ?Response
    {
        if ($request->method() === 'OPTIONS') {
            return $this->decorate(new Response('', 204), $request);
        }

        $response = (new StaticResourceProvider($this->app))->serve($request);
        return $response ? $this->decorate($response, $request) : null;
    }

    /** Add the security and CORS headers to a response. */
    public function decorate(Response $response, Request $request): Response
    {
        foreach (self::securityHeaders() + self::corsHeaders($request) as $name => $value) {
            if ($response->getHeader($name) === null) $response->header($name, $value);
        }
        return $this->dock($response, $request);
    }

    /**
     * The Cast dock: a floating button with links to the demo and the docs, added to HTML pages in development so it is
     * still there when the app's own layout is gone. Never in production, never in Cast/API/JSON answers, off with
     * `config('dock.enabled')` (env `CAST_DOCK=false`).
     */
    private function dock(Response $response, Request $request): Response
    {
        if (!Config::get('dock.enabled', true) || Config::get('app.env') === 'production') return $response;
        if ($request->isCast()) return $response;
        if (!str_contains(strtolower((string) $response->getHeader('Content-Type')), 'text/html')) return $response;
        $path = (string) parse_url($request->uri(), PHP_URL_PATH);
        if (preg_match('#/(cdocs|cast)(/|$)#', $path)) return $response;      // the docs viewer and the framework's own files
        $body = $response->body();
        if (!is_string($body) || $body === '' || str_contains($body, 'data-cast-dock-script')) return $response;

        $url = fn(string $p): string => function_exists('route') ? route($p) : $p;
        $tag = '<script src="' . htmlspecialchars($url('/cast/dock.js'), ENT_QUOTES) . '" data-cast-dock-script data-cast="off"'
            . ' data-demo="' . htmlspecialchars($url((string) Config::get('dock.demo_url', '/demo')), ENT_QUOTES) . '"'
            . ' data-docs="' . htmlspecialchars($url('/cdocs/'), ENT_QUOTES) . '" defer></script>';
        $tag .= self::probe($url('/cast/logo.svg'), $url('/'));
        $pos = strripos($body, '</body>');
        $response->body($pos === false ? $body . $tag : substr($body, 0, $pos) . $tag . substr($body, $pos));
        return $response;
    }

    /**
     * An inline script (it must not depend on the files it tests) that asks for one of the framework's own files. When that file does not come
     * back, the page is unstyled and the docs are blank, and the cause is the server, not the app: the PHP built-in server started without the router
     * script (`php -S 127.0.0.1:8000 -t public` instead of `php cast serve`), or a web server that does not send missing files to index.php.
     * It says so in a red bar at the bottom of the page, once per tab until dismissed. Development only, like the dock.
     */
    private static function probe(string $logo, string $home): string
    {
        $message = 'The framework\'s CSS, JS and /cdocs are not being served (this page loaded, but ' . $logo . ' did not). '
            . 'If you started PHP\'s built-in server yourself, start it with the router script: php cast serve   (or: php -S 127.0.0.1:8000 -t public public/index.php). '
            . 'On Apache or nginx the rewrite rules must send files that do not exist in public/ to index.php. Run: php cast static:check --url=<this address>';
        return '<script data-cast-dock-probe data-cast="off">(function(){try{if(sessionStorage.getItem("cast.probe.off"))return}catch(e){}'
            . 'fetch(' . json_encode($logo, JSON_UNESCAPED_SLASHES) . ',{method:"HEAD",cache:"no-store"}).then(function(r){if(r.ok)return;'
            . 'var d=document.createElement("div");d.setAttribute("data-cast-static-warning","");'
            . 'd.style.cssText="position:fixed;left:0;right:0;bottom:0;z-index:2147483647;background:#b3261e;color:#fff;font:14px/1.45 system-ui,sans-serif;padding:12px 44px 12px 16px;box-shadow:0 -4px 20px rgba(0,0,0,.3)";'
            . 'var t=document.createElement("span");t.textContent=' . json_encode($message, JSON_UNESCAPED_SLASHES) . ';d.appendChild(t);'
            . 'var x=document.createElement("button");x.textContent="\u00d7";x.setAttribute("aria-label","Dismiss");x.style.cssText="position:absolute;right:12px;top:8px;background:none;border:0;color:#fff;font-size:22px;cursor:pointer";'
            . 'x.onclick=function(){try{sessionStorage.setItem("cast.probe.off","1")}catch(e){}d.remove()};d.appendChild(x);document.body.appendChild(d)}).catch(function(){})})()</script>';
    }

    /**
     * Baseline security headers. Strict-Transport-Security is left to the web server or the app: sending it
     * from plain-HTTP local development would lock browsers to HTTPS for that host.
     * @return array<string, string>
     */
    public static function securityHeaders(): array
    {
        return [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'geolocation=(), camera=(), microphone=(), payment=(), usb=(), magnetometer=(), gyroscope=(), interest-cohort=()',
        ];
    }

    /**
     * CORS headers, only for exact origins listed in `config('cors.allowed_origins')` (env `CORS_ALLOWED_ORIGINS`,
     * comma separated). Never a wildcard together with credentials.
     * @return array<string, string>
     */
    public static function corsHeaders(Request $request): array
    {
        $origin = (string) $request->header('Origin', '');
        $allowed = (array) Config::get('cors.allowed_origins', []);
        if ($origin === '' || !in_array($origin, $allowed, true)) return [];

        return [
            'Access-Control-Allow-Origin' => $origin,
            'Access-Control-Allow-Credentials' => 'true',
            'Vary' => 'Origin',
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Origin, Content-Type, Accept, Authorization, X-Auth-Token, X-CSRF-TOKEN, X-XSRF-TOKEN, X-Requested-With, X-HTTP-Method-Override, X-Cast-Request, X-Cast-Type, X-Cast-Target, X-Cast-Guard',
            'Access-Control-Expose-Headers' => 'X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset, Retry-After',
            'Access-Control-Max-Age' => '1000',
        ];
    }
}

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
        $pos = strripos($body, '</body>');
        $response->body($pos === false ? $body . $tag : substr($body, 0, $pos) . $tag . substr($body, $pos));
        return $response;
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

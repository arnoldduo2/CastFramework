<?php

declare(strict_types=1);

namespace Cast\Services;

use Cast\App\Application;
use Cast\Core\Config;
use Cast\Http\Request;
use Cast\Http\Response;

/**
 * Serves static files (CSS, JS, fonts, images, vendor libraries) before routing, from the folders in
 * `config('static')`: URL prefix => ['dir' => folder relative to the app base, 'keep_prefix' => bool].
 *
 *   '/css/app.css'     => resources/css/app.css            (keep_prefix: true)
 *   '/public/x/y.js'   => public/assets/vendor/x/y.js      (keep_prefix: false hides the real folder)
 *
 * The resolved path must stay inside its folder (realpath check), and only known file types are served.
 */
final class StaticResourceProvider
{
    private const MIME = [
        'css' => 'text/css; charset=utf-8', 'js' => 'application/javascript; charset=utf-8', 'mjs' => 'application/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8', 'map' => 'application/json; charset=utf-8',
        'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'avif' => 'image/avif', 'ico' => 'image/x-icon', 'bmp' => 'image/bmp',
        'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf', 'eot' => 'application/vnd.ms-fontobject',
        'txt' => 'text/plain; charset=utf-8', 'html' => 'text/html; charset=utf-8', 'pdf' => 'application/pdf',
        'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mp3' => 'audio/mpeg', 'wav' => 'audio/wav',
    ];

    public function __construct(private Application $app) {}

    public function serve(Request $request): ?Response
    {
        if (!in_array($request->method(), ['GET', 'HEAD'], true)) return null;

        $path = rawurldecode($request->path());
        if (str_contains($path, "\0")) return null;

        foreach ((array) Config::get('static', []) as $prefix => $options) {
            $options = is_string($options) ? ['dir' => $options, 'keep_prefix' => true] : (array) $options;
            $prefix = trim((string) $prefix, '/');

            if (!str_starts_with($path, "/$prefix/")) continue;

            $relative = substr($path, strlen($prefix) + 2);
            $relative = ($options['keep_prefix'] ?? true) ? "$prefix/$relative" : $relative;

            $base = realpath($this->app->basePath((string) ($options['dir'] ?? '')));
            $file = $base === false ? false : realpath($base . DIRECTORY_SEPARATOR . $relative);
            if ($base === false || $file === false || !str_starts_with($file, $base . DIRECTORY_SEPARATOR) || !is_file($file)) {
                continue;
            }

            $type = self::MIME[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? null;
            if ($type === null) continue;

            return $this->respond($request, $file, $type);
        }
        return null;
    }

    private function respond(Request $request, string $file, string $type): Response
    {
        $modified = (int) filemtime($file);
        $since = $request->header('If-Modified-Since');
        if ($since !== null && ($time = strtotime($since)) !== false && $time >= $modified) {
            return (new Response('', 304))->header('Last-Modified', gmdate('D, d M Y H:i:s', $modified) . ' GMT');
        }

        // a ?v= version in the URL means the file can be cached for a long time
        $cache = $request->query('v') !== null ? 'public, max-age=31536000, immutable' : 'no-cache';
        $response = Response::file($file, $type)->header('Cache-Control', $cache);
        return $request->method() === 'HEAD' ? (new Response('', 200, $response->headers())) : $response;
    }
}

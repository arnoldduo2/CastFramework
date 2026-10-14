<?php

declare(strict_types=1);

namespace Cast\Support;

use Cast\App\Application;
use Cast\Core\Config;
use Cast\Http\Request;
use Cast\Http\Response;

/**
 * Native Node-free SSE Live-Reload / HMR controller for CastFramework.
 * Monitors view templates and assets for modifications and streams events
 * to connected browsers for instant CSS injection and in-place SPA view swaps.
 */
final class LiveReload
{
    public static function enabled(): bool
    {
        return DevError::enabled() && Config::get('app.hmr', 'sse') !== 'disabled';
    }

    public static function handle(Application $app, Request $request): Response
    {
        if (!self::enabled() || Config::get('app.hmr', 'sse') === 'vite') {
            return new Response('', 204);
        }

        // Send SSE streaming headers
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-transform');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }
        @ini_set('zlib.output_compression', '0');
        @ini_set('implicit_flush', '1');
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }

        // Send initial connection event
        echo "event: ping\ndata: {\"status\":\"connected\"}\n\n";
        @flush();

        $watchDirs = [
            $app->basePath('resources/views'),
            $app->basePath('resources/css'),
            $app->basePath('resources/js'),
        ];

        $lastState = self::snapshot($watchDirs);

        // Keep connection open up to 25 seconds (browser EventSource auto-reconnects seamlessly)
        $start = time();
        while (time() - $start < 25) {
            if (connection_aborted()) {
                break;
            }

            usleep(350000); // 350ms check
            clearstatcache();
            $currentState = self::snapshot($watchDirs);

            if ($currentState !== $lastState) {
                $change = self::detectChange($lastState, $currentState);
                $event = $change['type']; // 'css', 'view', or 'reload'
                $data = json_encode($change['data'] ?? []);
                echo "event: {$event}\ndata: {$data}\n\n";
                @flush();
                $lastState = $currentState;
            }
        }

        exit(0);
    }

    /**
     * @param list<string> $dirs
     * @return array<string, int>
     */
    private static function snapshot(array $dirs): array
    {
        $files = [];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) continue;
            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
                );
                foreach ($iterator as $file) {
                    /** @var \SplFileInfo $file */
                    if ($file->isFile()) {
                        $files[$file->getPathname()] = $file->getMTime();
                    }
                }
            } catch (\Throwable) {}
        }
        return $files;
    }

    /**
     * @param array<string, int> $old
     * @param array<string, int> $new
     * @return array{type: string, data: array<string, mixed>}
     */
    private static function detectChange(array $old, array $new): array
    {
        foreach ($new as $path => $mtime) {
            if (!isset($old[$path]) || $old[$path] !== $mtime) {
                return self::classifyChange($path);
            }
        }
        foreach ($old as $path => $mtime) {
            if (!isset($new[$path])) {
                return self::classifyChange($path);
            }
        }
        return ['type' => 'reload', 'data' => []];
    }

    /**
     * @return array{type: string, data: array<string, mixed>}
     */
    private static function classifyChange(string $path): array
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'css') {
            return ['type' => 'css', 'data' => ['file' => basename($path)]];
        }
        if (str_ends_with($path, '.cast.php')) {
            return ['type' => 'view', 'data' => ['file' => basename($path)]];
        }
        return ['type' => 'reload', 'data' => ['file' => basename($path)]];
    }
}
